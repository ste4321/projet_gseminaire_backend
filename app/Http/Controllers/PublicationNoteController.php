<?php

namespace App\Http\Controllers;

use App\Events\NotesPubliees;
use App\Models\EtudiantParcours;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\PublicationNote;
use App\Models\Semestre;
use App\Services\ConfirmationService;
use App\Services\DiffusionService;
use Illuminate\Http\Request;

/**
 * Publication des notes par semestre, pour une année académique et un niveau.
 * Tant qu'un semestre n'est pas publié, ses notes ne sont pas envoyées aux étudiants.
 * Publication et retrait sont signalés en direct aux étudiants concernés (événement NotesPubliees).
 */
class PublicationNoteController extends Controller
{
    /** GET /api/notes/publications?annee=&niveau= : semestres du niveau, avancement de la saisie, état de publication */
    public function index(Request $request)
    {
        ['annee' => $annee, 'niveau' => $niveau] = $request->validate([
            'annee'  => 'required|integer|exists:annee_aca,id',
            'niveau' => 'required|integer|exists:niveaux,id',
        ]);

        $parcoursIds = EtudiantParcours::where('id_annee_aca', $annee)->where('id_niveau', $niveau)->pluck('id');
        $publications = PublicationNote::with('auteur.admin')
            ->where('id_annee_aca', $annee)
            ->where('id_niveau', $niveau)
            ->get()
            ->keyBy('id_semestre');

        return Matiere::auProgramme($annee)
            ->with('semestre')
            ->where('matieres.id_niveau', $niveau)
            ->whereNotNull('matieres.id_semestre')
            ->get()
            ->groupBy('id_semestre')
            ->map(function ($matieres, $idSemestre) use ($parcoursIds, $publications) {
                $publication = $publications->get($idSemestre);

                return [
                    'id'              => (int) $idSemestre,
                    'semestre'        => $matieres->first()->semestre?->semestre,
                    'matieres'        => $matieres->count(),
                    'etudiants'       => $parcoursIds->count(),
                    'notes_attendues' => $matieres->count() * $parcoursIds->count(),
                    'notes_saisies'   => Note::whereIn('id_etudiant_parcours', $parcoursIds)
                        ->whereIn('id_matiere', $matieres->pluck('id'))
                        ->whereNotNull('note')
                        ->count(),
                    'publie'          => (bool) $publication,
                    'publie_le'       => $publication?->created_at?->toIso8601String(),
                    'publie_par'      => $publication?->auteur?->admin?->nom_prenom ?? ($publication ? 'Administration' : null),
                ];
            })
            ->sortBy('id')
            ->values();
    }

    /** POST /api/notes/publications : publie un semestre (sans effet s'il l'est déjà) */
    public function store(Request $request, ConfirmationService $confirmations)
    {
        $cible = $this->cible($request);
        $confirmations->exiger($request->user(), 'publier_notes', $request->input('code_confirmation'));

        $publication = PublicationNote::firstOrCreate($cible, ['publie_par' => $request->user()->id]);
        if ($publication->wasRecentlyCreated) {
            $this->diffuser(NotesPubliees::PUBLIEES, $cible);
        }

        return response()->json([
            'message' => 'Notes publiées : les étudiants peuvent maintenant les consulter.',
            'publication' => $publication,
        ], $publication->wasRecentlyCreated ? 201 : 200);
    }

    /** DELETE /api/notes/publications : retire la publication (les notes redeviennent invisibles pour les étudiants) */
    public function destroy(Request $request)
    {
        $cible = $this->cible($request);
        if (PublicationNote::where($cible)->delete()) {
            $this->diffuser(NotesPubliees::RETIREES, $cible);
        }

        return response()->json(['message' => 'Publication retirée : les étudiants ne voient plus ces notes.']);
    }

    /** Prévient les étudiants de l'année et du niveau : leur relevé se recharge */
    private function diffuser(string $action, array $cible): void
    {
        DiffusionService::envoyer(
            new NotesPubliees(
                $action,
                Semestre::find($cible['id_semestre'])?->semestre,
                DiffusionService::etudiantsInscrits($cible['id_annee_aca'], $cible['id_niveau']),
            ),
            $cible + ['action' => $action]
        );
    }

    private function cible(Request $request): array
    {
        return $request->validate([
            'id_annee_aca' => 'required|integer|exists:annee_aca,id',
            'id_niveau'    => 'required|integer|exists:niveaux,id',
            'id_semestre'  => 'required|integer|exists:semestres,id',
        ]);
    }
}
