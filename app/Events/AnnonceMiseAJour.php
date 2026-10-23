<?php

namespace App\Events;

use App\Models\Annonce;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

/**
 * Une annonce a été publiée, modifiée ou supprimée.
 * Diffusée en direct (WebSocket) sur le canal privé de chaque destinataire
 * (« App.Models.User.{id} », voir routes/channels.php) : l'application de gestion
 * met aussitôt à jour la liste des annonces, et notifie les nouvelles.
 */
class AnnonceMiseAJour implements ShouldBroadcastNow
{
    use Dispatchable;

    public const CREEE = 'creee';
    public const MODIFIEE = 'modifiee';
    public const SUPPRIMEE = 'supprimee';

    /** @param int[] $idsUtilisateurs comptes à prévenir */
    public function __construct(
        public string $action,
        public Annonce $annonce,
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
        return 'annonce.maj';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'annonce' => $this->action === self::SUPPRIMEE
                ? ['id' => $this->annonce->id, 'titre' => $this->annonce->titre]
                : [
                    'id'            => $this->annonce->id,
                    'titre'         => $this->annonce->titre,
                    'extrait'       => Str::limit($this->annonce->description, 140),
                    'expediteur'    => $this->annonce->expediteur,
                    'destinataires' => $this->annonce->destinataires,
                    'created_at'    => $this->annonce->created_at,
                ],
        ];
    }
}
