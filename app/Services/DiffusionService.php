<?php

namespace App\Services;

use App\Models\Etudiant;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Notifications en direct (WebSocket, Laravel Reverb) sur le canal privé de chaque destinataire
 * (« App.Models.User.{id} », voir routes/channels.php) : annonces, notes publiées, emploi du temps.
 */
class DiffusionService
{
    /**
     * Comptes actifs des étudiants inscrits une année (et, si précisé, à un niveau).
     * Les comptes désactivés n'ont pas accès à leur espace : inutile de les prévenir.
     */
    public static function etudiantsInscrits(int $anneeId, ?int $niveauId = null): array
    {
        $ids = Etudiant::query()
            ->whereNotNull('id_user_cession')
            ->whereHas('parcours', fn ($q) => $q
                ->where('id_annee_aca', $anneeId)
                ->when($niveauId, fn ($q) => $q->where('id_niveau', $niveauId)))
            ->pluck('id_user_cession');

        return self::actifs($ids->all());
    }

    /** Ne garde que les comptes activés, sans doublon */
    public static function actifs(array $idsUtilisateurs): array
    {
        if (!$idsUtilisateurs) {
            return [];
        }

        return User::whereIn('id', array_unique($idsUtilisateurs))->where('is_verified', true)->pluck('id')->all();
    }

    /**
     * Envoie l'événement. Si le serveur WebSocket est arrêté, l'opération (publication, ajout…) reste faite :
     * les destinataires la verront à leur prochain chargement.
     */
    public static function envoyer(object $evenement, array $contexte = []): void
    {
        if (property_exists($evenement, 'idsUtilisateurs') && !$evenement->idsUtilisateurs) {
            return; // personne à prévenir
        }
        try {
            broadcast($evenement);
        } catch (\Throwable $e) {
            Log::warning('Diffusion en direct impossible (serveur Reverb arrêté ?)', $contexte + [
                'evenement' => class_basename($evenement),
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
