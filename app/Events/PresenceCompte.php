<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Statut « En ligne » d'un compte (connexion, signal de présence, déconnexion), diffusé en direct
 * sur le canal privé « comptes » réservé aux administrateurs (routes/channels.php) :
 * la page « Comptes et accès » se met à jour sans relire la liste.
 * Le passage hors ligne d'une application fermée sans déconnexion est déduit par la page
 * (plus de signal depuis User::PRESENCE_SECONDES).
 */
class PresenceCompte implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public bool $enLigne,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('comptes');
    }

    public function broadcastAs(): string
    {
        return 'presence.maj';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->user->id,
            'role' => $this->user->role,
            'en_ligne' => $this->enLigne,
            'derniere_activite_at' => $this->user->derniere_activite_at,
            'derniere_connexion_at' => $this->user->derniere_connexion_at,
        ];
    }
}
