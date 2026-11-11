<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnneeAca;
use App\Models\Contact;
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

        // Inscrits de l'année par niveau : une seule requête groupée (au lieu d'une par niveau)
        $niveaux = Niveau::orderBy('niveau')->get(['id', 'niveau']);
        $inscritsParNiveau = $annee
            ? EtudiantParcours::where('id_annee_aca', $annee->id)
                ->selectRaw('id_niveau, COUNT(*) as total')
                ->groupBy('id_niveau')
                ->pluck('total', 'id_niveau')
                ->map(fn ($total) => (int) $total)
            : collect();
        $inscrits = $inscritsParNiveau->sum();

        // Inscrits par niveau (tous les niveaux, même vides)
        $parNiveau = $niveaux->map(fn ($niveau) => [
            'niveau' => $niveau->niveau,
            'total'  => $inscritsParNiveau->get($niveau->id, 0),
        ]);

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

        // Comptes de connexion par rôle : une seule requête groupée (au lieu de neuf)
        $parRole = User::query()
            ->selectRaw('role, COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN is_verified = false THEN 1 ELSE 0 END) as inactifs')
            ->selectRaw('SUM(CASE WHEN must_change_password = true THEN 1 ELSE 0 END) as provisoires')
            ->groupBy('role')
            ->get()
            ->keyBy('role');
        $comptes = [];
        foreach (['admin', 'prof', 'etudiant'] as $role) {
            $ligne = $parRole->get($role);
            $comptes[$role] = [
                'total'       => (int) ($ligne->total ?? 0),
                'inactifs'    => (int) ($ligne->inactifs ?? 0),
                'provisoires' => (int) ($ligne->provisoires ?? 0),
            ];
        }

        // Notes de l'année : saisies / attendues (inscrits × matières de leur niveau)
        $notesSaisies = $annee
            ? Note::whereNotNull('note')->whereHas('etudiantParcours', fn ($q) => $q->where('id_annee_aca', $annee->id))->count()
            : 0;
        $notesAttendues = $annee
            ? (int) DB::table('etudiant_parcours')
                // Une note attendue par inscription et par matière au programme de l'année de son niveau
                ->join('programme_matieres', 'programme_matieres.id_annee_aca', '=', 'etudiant_parcours.id_annee_aca')
                ->join('matieres', function ($join) {
                    $join->on('matieres.id', '=', 'programme_matieres.id_matiere')
                        ->on('matieres.id_niveau', '=', 'etudiant_parcours.id_niveau');
                })
                ->where('etudiant_parcours.id_annee_aca', $annee->id)
                ->count()
            : 0;

        $totalEtudiants = Etudiant::count();
        $totalEnseignants = Enseignant::count();

        return response()->json([
            'annee'   => $annee,
            'annees'  => $annees,
            'etudiants' => [
                'inscrits'    => $inscrits,
                'total'       => $totalEtudiants,
                'par_niveau'  => $parNiveau,
                'par_diocese' => $parDiocese,
            ],
            'enseignants' => $totalEnseignants,
            'comptes'     => $comptes,
            'notes' => [
                'saisies'   => $notesSaisies,
                'attendues' => $notesAttendues,
            ],
            // Notes par niveau et par semestre : avancement de la saisie et publication (bloc « À faire »)
            'publications' => $annee ? $this->publications($annee->id, $niveaux, $inscritsParNiveau) : [],
            // Messages du site vitrine pas encore lus (page « Messages »)
            'messages_non_lus' => Contact::whereNull('lu_le')->count(),
            'annonces_recentes' => Annonce::with('niveau:id,niveau')
                ->latest()
                ->limit(4)
                ->get(['id', 'titre', 'description', 'expediteur', 'created_at', 'destinataires', 'id_niveau']),

            // Compatibilité avec l'ancien format
            'total_etudiants' => $totalEtudiants,
            'total_profs'     => $totalEnseignants,
        ]);
    }

    /**
     * Pour chaque niveau ayant des inscrits : semestres, notes saisies / attendues, publication.
     * Trois requêtes en tout (matières au programme, notes saisies groupées, publications),
     * quel que soit le nombre de niveaux et de semestres.
     */
    private function publications(int $anneeId, $niveaux, $inscritsParNiveau): array
    {
        $publies = PublicationNote::where('id_annee_aca', $anneeId)
            ->get(['id_niveau', 'id_semestre'])
            ->map(fn ($p) => "{$p->id_niveau}-{$p->id_semestre}")
            ->flip();

        // Matières au programme de l'année, par niveau puis par semestre
        $matieres = Matiere::auProgramme($anneeId)
            ->with('semestre:id,semestre')
            ->whereNotNull('matieres.id_semestre')
            ->orderBy('matieres.id_semestre')
            ->get()
            ->groupBy(['id_niveau', 'id_semestre']);

        // Notes saisies de l'année, par niveau et semestre : seules comptent les matières au programme
        // du niveau de l'inscription (même règle que le calcul des notes attendues)
        $saisies = DB::table('notes')
            ->join('etudiant_parcours as ep', 'ep.id', '=', 'notes.id_etudiant_parcours')
            ->join('matieres as m', function ($join) {
                $join->on('m.id', '=', 'notes.id_matiere')->on('m.id_niveau', '=', 'ep.id_niveau');
            })
            ->join('programme_matieres as pm', function ($join) use ($anneeId) {
                $join->on('pm.id_matiere', '=', 'm.id')->where('pm.id_annee_aca', $anneeId);
            })
            ->where('ep.id_annee_aca', $anneeId)
            ->whereNotNull('notes.note')
            ->whereNotNull('m.id_semestre')
            ->groupBy('ep.id_niveau', 'm.id_semestre')
            ->selectRaw('ep.id_niveau, m.id_semestre, COUNT(*) as total')
            ->get()
            ->mapWithKeys(fn ($r) => ["{$r->id_niveau}-{$r->id_semestre}" => (int) $r->total]);

        $lignes = [];
        foreach ($niveaux as $niveau) {
            $inscrits = $inscritsParNiveau->get($niveau->id, 0);
            if ($inscrits === 0) {
                continue;
            }
            foreach ($matieres->get($niveau->id, collect()) as $idSemestre => $liste) {
                $cle = "{$niveau->id}-{$idSemestre}";
                $lignes[] = [
                    'niveau'          => $niveau->niveau,
                    'semestre'        => $liste->first()->semestre?->semestre,
                    'notes_saisies'   => $saisies->get($cle, 0),
                    'notes_attendues' => $liste->count() * $inscrits,
                    'publie'          => $publies->has($cle),
                ];
            }
        }

        return $lignes;
    }
}
