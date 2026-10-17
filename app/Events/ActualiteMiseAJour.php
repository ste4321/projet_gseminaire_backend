<?php

namespace App\Events;

use App\Models\Info;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Une actualité du site vitrine a été publiée, modifiée ou supprimée.
 * Diffusée en direct (WebSocket) sur le canal public « actualites » :
 * le site vitrine met sa liste à jour sans rechargement.
 */
class ActualiteMiseAJour implements ShouldBroadcastNow
{
    use Dispatchable;

    public const CREEE = 'creee';
    public const MODIFIEE = 'modifiee';
    public const SUPPRIMEE = 'supprimee';

    public function __construct(
        public string $action,
        public Info $info,
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('actualites');
    }

    public function broadcastAs(): string
    {
        return 'actualite.maj';
    }

    /** Uniquement les champs publics (ceux déjà renvoyés par GET /api/infos) */
    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'info' => $this->action === self::SUPPRIMEE
                ? ['id' => $this->info->id]
                : $this->info->only(['id', 'auteur', 'titre', 'description', 'created_at', 'updated_at']),
        ];
    }
}
