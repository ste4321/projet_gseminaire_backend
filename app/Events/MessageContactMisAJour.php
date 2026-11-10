<?php

namespace App\Events;

use App\Models\Contact;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un message du formulaire « Contactez-nous » a été reçu, modifié (lu / répondu) ou supprimé.
 * Diffusé en direct sur le canal privé « administration » (routes/channels.php) :
 * la cloche, le menu et la page « Messages du site » de chaque administrateur connecté
 * se mettent à jour sans recharger.
 */
class MessageContactMisAJour implements ShouldBroadcastNow
{
    use Dispatchable;

    public const RECU = 'recu';
    public const MODIFIE = 'modifie';
    public const SUPPRIME = 'supprime';

    public function __construct(
        public string $action,
        public Contact $contact,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('administration');
    }

    public function broadcastAs(): string
    {
        return 'contact.maj';
    }

    public function broadcastWith(): array
    {
        return [
            'action'    => $this->action,
            'message'   => $this->action === self::SUPPRIME
                ? ['id' => $this->contact->id]
                : $this->contact->only(['id', 'name', 'email', 'message', 'lu_le', 'repondu_le', 'created_at']),
            // Compteurs à jour : la cloche et les filtres n'ont rien à recalculer
            'compteurs' => [
                'tous'         => Contact::count(),
                'non_lus'      => Contact::whereNull('lu_le')->count(),
                'non_repondus' => Contact::whereNull('repondu_le')->count(),
            ],
        ];
    }
}
