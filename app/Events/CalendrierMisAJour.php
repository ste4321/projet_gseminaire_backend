<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Le calendrier affiché sur le site vitrine a changé (événement, bornes, publication).
 * Diffusé en direct sur le canal public « calendrier » : le site recharge GET /api/calendrier.
 * Aucune donnée n'est jointe, pour ne jamais exposer un calendrier en brouillon.
 */
class CalendrierMisAJour implements ShouldBroadcastNow
{
    use Dispatchable;

    public function broadcastOn(): Channel
    {
        return new Channel('calendrier');
    }

    public function broadcastAs(): string
    {
        return 'calendrier.maj';
    }

    public function broadcastWith(): array
    {
        return [];
    }
}
