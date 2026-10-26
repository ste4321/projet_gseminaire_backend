<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Les notes d'un semestre viennent d'être publiées (ou leur publication retirée) pour une année et un niveau.
 * Diffusée en direct aux étudiants concernés : leur relevé se met à jour, une publication est notifiée.
 * Les notes elles-mêmes ne voyagent pas ici : l'étudiant les recharge par l'API (accès contrôlé).
 */
class NotesPubliees implements ShouldBroadcastNow
{
    use Dispatchable;

    public const PUBLIEES = 'publiees';
    public const RETIREES = 'retirees';

    /** @param int[] $idsUtilisateurs comptes à prévenir */
    public function __construct(
        public string $action,
        public ?string $semestre,
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
        return 'notes.maj';
    }

    public function broadcastWith(): array
    {
        return ['action' => $this->action, 'semestre' => $this->semestre];
    }
}
