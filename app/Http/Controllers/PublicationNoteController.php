<?php

namespace App\Http\Controllers;

use App\Models\EtudiantParcours;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\PublicationNote;
use App\Services\ConfirmationService;
use Illuminate\Http\Request;

/**
 * Publication des notes par semestre, pour une année académique et un niveau.
 * Tant qu'un semestre n'est pas publié, ses notes ne sont pas envoyées aux étudiants.
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

        return Matiere::with('semestre')
            ->where('id_niveau', $niveau)
            ->whereNotNull('id_semestre')
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

        return response()->json([
            'message' => 'Notes publiées : les étudiants peuvent maintenant les consulter.',
            'publication' => $publication,
        ], $publication->wasRecentlyCreated ? 201 : 200);
    }

    /** DELETE /api/notes/publications : retire la publication (les notes redeviennent invisibles pour les étudiants) */
    public function destroy(Request $request)
    {
        PublicationNote::where($this->cible($request))->delete();

        return response()->json(['message' => 'Publication retirée : les étudiants ne voient plus ces notes.']);
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
