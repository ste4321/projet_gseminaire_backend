<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnneeAca;
use App\Models\Annonce;
use App\Models\Enseignant;
use App\Models\Etudiant;
use App\Models\EtudiantParcours;
use App\Models\Matiere;
use App\Models\Niveau;
use App\Models\Note;
use App\Models\PublicationNote;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Statistiques du tableau de bord administrateur (toutes calculées depuis la base).
 * GET /api/dashboard/stats?annee=<id>  (par défaut : année académique la plus récente)
 */
class DashboardController extends Controller
{
    public function stats(Request $request)
    {
        $annees = AnneeAca::orderByDesc('id')->get(['id', 'annee_aca']);
        $annee = $request->filled('annee')
            ? $annees->firstWhere('id', (int) $request->query('annee'))
            : $annees->first();

        $parcoursAnnee = EtudiantParcours::query()->when($annee, fn ($q) => $q->where('id_annee_aca', $annee->id));
        $inscrits = $annee ? (clone $parcoursAnnee)->count() : 0;

        // Inscrits par niveau (tous les niveaux, même vides)
        $parNiveau = Niveau::orderBy('niveau')->get(['id', 'niveau'])->map(function ($niveau) use ($annee) {
            return [
                'niveau' => $niveau->niveau,
                'total'  => $annee
                    ? EtudiantParcours::where('id_annee_aca', $annee->id)->where('id_niveau', $niveau->id)->count()
                    : 0,
            ];
        });

        // Origine des inscrits (champ « diocèse »)
        $parDiocese = $annee
            ? DB::table('etudiant_parcours')
                ->join('etudiants', 'etudiants.id', '=', 'etudiant_parcours.id_etudiant')
                ->where('etudiant_parcours.id_annee_aca', $annee->id)
                ->selectRaw("COALESCE(NULLIF(TRIM(etudiants.diocese), ''), 'Non renseigné') as diocese, COUNT(*) as total")
                ->groupBy(DB::raw("COALESCE(NULLIF(TRIM(etudiants.diocese), ''), 'Non renseigné')"))
                ->orderByDesc('total')
                ->get()
                ->map(fn ($r) => ['diocese' => $r->diocese, 'total' => (int) $r->total])
            : collect();

        // Comptes de connexion par rôle
        $comptes = [];
        foreach (['admin', 'prof', 'etudiant'] as $role) {
            $base = User::where('role', $role);
            $comptes[$role] = [
                'total'       => (clone $base)->count(),
                'inactifs'    => (clone $base)->where('is_verified', false)->count(),
                'provisoires' => (clone $base)->where('must_change_password', true)->count(),
            ];
        }

        // Notes de l'année : saisies / attendues (inscrits × matières de leur niveau)
        $notesSaisies = $annee
            ? Note::whereNotNull('note')->whereHas('etudiantParcours', fn ($q) => $q->where('id_annee_aca', $annee->id))->count()
            : 0;
        $notesAttendues = $annee
            ? (int) DB::table('etudiant_parcours')
                ->join('matieres', 'matieres.id_niveau', '=', 'etudiant_parcours.id_niveau')
                ->where('etudiant_parcours.id_annee_aca', $annee->id)
                ->count()
            : 0;

        return response()->json([
            'annee'   => $annee,
            'annees'  => $annees,
            'etudiants' => [
                'inscrits'    => $inscrits,
                'total'       => Etudiant::count(),
                'par_niveau'  => $parNiveau,
                'par_diocese' => $parDiocese,
            ],
            'enseignants' => Enseignant::count(),
            'comptes'     => $comptes,
            'notes' => [
                'saisies'   => $notesSaisies,
                'attendues' => $notesAttendues,
            ],
            // Notes par niveau et par semestre : avancement de la saisie et publication (bloc « À faire »)
            'publications' => $annee ? $this->publications($annee->id) : [],
            'annonces_recentes' => Annonce::with('niveau:id,niveau')
                ->latest()
                ->limit(4)
                ->get(['id', 'titre', 'description', 'expediteur', 'created_at', 'id_niveau']),

            // Compatibilité avec l'ancien format
            'total_etudiants' => Etudiant::count(),
            'total_profs'     => Enseignant::count(),
        ]);
    }

    /** Pour chaque niveau ayant des inscrits : semestres, notes saisies / attendues, publication */
    private function publications(int $anneeId): array
    {
        $publies = PublicationNote::where('id_annee_aca', $anneeId)
            ->get(['id_niveau', 'id_semestre'])
            ->map(fn ($p) => "{$p->id_niveau}-{$p->id_semestre}")
            ->flip();

        $lignes = [];
        foreach (Niveau::orderBy('niveau')->get(['id', 'niveau']) as $niveau) {
            $parcoursIds = EtudiantParcours::where('id_annee_aca', $anneeId)->where('id_niveau', $niveau->id)->pluck('id');
            if ($parcoursIds->isEmpty()) {
                continue;
            }
            $matieres = Matiere::with('semestre:id,semestre')
                ->where('id_niveau', $niveau->id)
                ->whereNotNull('id_semestre')
                ->orderBy('id_semestre')
                ->get(['id', 'id_semestre'])
                ->groupBy('id_semestre');

            foreach ($matieres as $idSemestre => $liste) {
                $lignes[] = [
                    'niveau'          => $niveau->niveau,
                    'semestre'        => $liste->first()->semestre?->semestre,
                    'notes_saisies'   => Note::whereIn('id_etudiant_parcours', $parcoursIds)
                        ->whereIn('id_matiere', $liste->pluck('id'))
                        ->whereNotNull('note')
                        ->count(),
                    'notes_attendues' => $liste->count() * $parcoursIds->count(),
                    'publie'          => $publies->has("{$niveau->id}-{$idSemestre}"),
                ];
            }
        }

        return $lignes;
    }
}
