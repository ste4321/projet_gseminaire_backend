<?php

namespace App\Services;

use App\Models\Etudiant;
use App\Models\EtudiantParcours;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Départ d'un étudiant : le dossier n'est jamais supprimé (notes, bulletins, historique restent consultables).
 * - l'inscription concernée prend le statut de sortie (diplome, abandon, transfere, exclu) ;
 * - le dossier porte la date et le motif ;
 * - abandon, transfert, exclusion : compte désactivé et sessions fermées.
 *   Diplômé : le compte reste actif, pour qu'il puisse encore télécharger ses bulletins.
 */
class SortieService
{
    public function declarer(Etudiant $etudiant, string $statut, ?string $date = null, ?string $motif = null, ?EtudiantParcours $parcours = null): void
    {
        DB::transaction(function () use ($etudiant, $statut, $date, $motif, $parcours) {
            $parcours ??= $etudiant->parcours()->orderByDesc('id_annee_aca')->first();
            $parcours?->update(['statut' => $statut]);

            $etudiant->update([
                'date_sortie'  => $date ? Carbon::parse($date)->toDateString() : now()->toDateString(),
                'motif_sortie' => $motif ?: null,
            ]);

            if (in_array($statut, EtudiantParcours::SORTIES, true)) {
                $this->desactiverCompte($etudiant);
            }
        });
    }

    /** Retour de l'étudiant (sortie déclarée par erreur, réintégration) : inscription et compte réactivés */
    public function annuler(Etudiant $etudiant): void
    {
        DB::transaction(function () use ($etudiant) {
            $parcours = $etudiant->parcours()->orderByDesc('id_annee_aca')->first();
            if ($parcours && in_array($parcours->statut, [...EtudiantParcours::SORTIES, EtudiantParcours::DIPLOME], true)) {
                $parcours->update(['statut' => EtudiantParcours::INSCRIT]);
            }

            $etudiant->update(['date_sortie' => null, 'motif_sortie' => null]);
            $etudiant->userCession?->update(['is_verified' => true]);
        });
    }

    private function desactiverCompte(Etudiant $etudiant): void
    {
        $compte = $etudiant->userCession;
        if (!$compte) {
            return;
        }
        $compte->update(['is_verified' => false]);
        $compte->tokens()->delete(); // ses sessions ouvertes sont fermées
    }
}
