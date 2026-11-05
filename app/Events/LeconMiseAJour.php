<?php

namespace App\Events;

use App\Models\Lecon;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Une leçon vient d'être publiée, modifiée, retirée ou supprimée par son enseignant.
 * Diffusée en direct aux étudiants du niveau de la matière : leur liste de cours se met à jour,
 * une nouvelle publication est notifiée. Le contenu ne voyage pas ici : l'étudiant le recharge par l'API.
 */
class LeconMiseAJour implements ShouldBroadcastNow
{
    use Dispatchable;

    public const PUBLIEE = 'publiee';
    public const MODIFIEE = 'modifiee';
    public const RETIREE = 'retiree';

    /** @param int[] $idsUtilisateurs comptes à prévenir */
    public function __construct(
        public string $action,
        public Lecon $lecon,
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
        return 'lecons.maj';
    }

    public function broadcastWith(): array
    {
        return [
            'action'     => $this->action,
            'id'         => $this->lecon->id,
            'titre'      => $this->lecon->titre,
            'matiere'    => $this->lecon->matiere?->matiere,
            'enseignant' => $this->lecon->enseignant?->nom_prenom,
        ];
    }
}
