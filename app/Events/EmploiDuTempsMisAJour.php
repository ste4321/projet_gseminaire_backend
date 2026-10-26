<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Une image de l'emploi du temps a été ajoutée, remplacée ou supprimée.
 * Diffusée en direct aux étudiants inscrits cette année : leur emploi du temps se met à jour,
 * un ajout ou un remplacement est notifié.
 */
class EmploiDuTempsMisAJour implements ShouldBroadcastNow
{
    use Dispatchable;

    public const AJOUTE = 'ajoute';
    public const REMPLACE = 'remplace';
    public const SUPPRIME = 'supprime';

    /** @param int[] $idsUtilisateurs comptes à prévenir */
    public function __construct(
        public string $action,
        public int $semestre,
        public array $idsUtilisateurs,
    ) {
    }

    /** Le diffuseur envoie par paquets de 100 canaux */
    public function broadcastOn(): array
    {
        return array_map(fn ($id) => new PrivateChannel('App.Models.User.' . $id), $this->idsUtilisateurs);
    }

    public function broadcastAs(): string
    {
        return 'edt.maj';
    }

    public function broadcastWith(): array
    {
        return ['action' => $this->action, 'semestre' => $this->semestre];
    }
}
