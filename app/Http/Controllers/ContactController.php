<?php

namespace App\Http\Controllers;

use App\Events\MessageContactMisAJour;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Formulaire « Contactez-nous » du site vitrine.
 * - POST   /api/postContact      (public, limité à 5 envois par minute) : enregistre le message
 * - GET    /api/contacts         (admin) : liste paginée, filtres et compteurs
 * - PATCH  /api/contacts/{id}    (admin) : marquer lu / non lu, répondu / non répondu
 * - POST   /api/contacts/lus     (admin) : tous les messages marqués comme lus (cloche)
 * - DELETE /api/contacts/{id}    (admin)
 * Chaque changement est diffusé en direct aux administrateurs (App\Events\MessageContactMisAJour).
 */
class ContactController extends Controller
{
    private const PAR_PAGE = 15;

    public function store(Request $request)
    {
        // Champ invisible pour les visiteurs : seuls les robots le remplissent.
        // On répond comme si tout allait bien, sans rien enregistrer.
        if ($request->filled('site_web')) {
            return response()->json(['message' => 'Message envoyé avec succès !'], 201);
        }

        $validated = $request->validate([
            'name'    => ['required', 'string', 'max:255'],
            'email'   => ['required', 'email', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $contact = Contact::create(array_map('trim', $validated));
        $this->diffuser(MessageContactMisAJour::RECU, $contact);

        return response()->json(['message' => 'Message envoyé avec succès !'], 201);
    }

    /** ?filtre=non_lus|non_repondus (défaut : tous) · ?q=recherche (nom, e-mail, texte) · ?page= */
    public function index(Request $request)
    {
        $request->validate([
            'filtre' => ['nullable', 'in:tous,non_lus,non_repondus'],
            'q'      => ['nullable', 'string', 'max:255'],
        ]);

        $q = trim((string) $request->query('q', ''));
        $messages = Contact::query()
            ->when($request->query('filtre') === 'non_lus', fn ($r) => $r->whereNull('lu_le'))
            ->when($request->query('filtre') === 'non_repondus', fn ($r) => $r->whereNull('repondu_le'))
            ->when($q !== '', function ($r) use ($q) {
                $terme = '%' . mb_strtolower($q) . '%';
                $r->where(fn ($w) => $w
                    ->whereRaw('LOWER(name) LIKE ?', [$terme])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$terme])
                    ->orWhereRaw('LOWER(message) LIKE ?', [$terme]));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PAR_PAGE);

        return response()->json([
            'data'      => $messages->items(),
            'page'      => $messages->currentPage(),
            'pages'     => $messages->lastPage(),
            'total'     => $messages->total(),
            'compteurs' => [
                'tous'         => Contact::count(),
                'non_lus'      => Contact::whereNull('lu_le')->count(),
                'non_repondus' => Contact::whereNull('repondu_le')->count(),
            ],
        ]);
    }

    /** { lu: bool } et/ou { repondu: bool } — répondre à un message le marque aussi comme lu */
    public function update(Request $request, $id)
    {
        $contact = Contact::findOrFail($id);
        $donnees = $request->validate([
            'lu'      => ['sometimes', 'boolean'],
            'repondu' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('lu', $donnees)) {
            $contact->lu_le = $donnees['lu'] ? ($contact->lu_le ?? now()) : null;
        }
        if (array_key_exists('repondu', $donnees)) {
            $contact->repondu_le = $donnees['repondu'] ? ($contact->repondu_le ?? now()) : null;
            if ($donnees['repondu']) {
                $contact->lu_le ??= now();
            }
        }
        $contact->save();
        $this->diffuser(MessageContactMisAJour::MODIFIE, $contact);

        return $contact;
    }

    public function toutLu()
    {
        $nombre = Contact::whereNull('lu_le')->update(['lu_le' => now()]);
        // Un seul signal pour tous : les administrateurs connectés relisent leurs compteurs
        $dernier = Contact::latest('id')->first();
        if ($nombre > 0 && $dernier) {
            $this->diffuser(MessageContactMisAJour::MODIFIE, $dernier);
        }

        return response()->json(['lus' => $nombre]);
    }

    public function destroy($id)
    {
        $contact = Contact::findOrFail($id);
        $contact->delete();
        $this->diffuser(MessageContactMisAJour::SUPPRIME, $contact);

        return response()->json(['message' => 'Message supprimé.']);
    }

    /**
     * Prévient en direct les administrateurs connectés. Si le serveur WebSocket est arrêté,
     * l'opération est quand même faite : les pages se mettront à jour à leur prochaine lecture.
     */
    private function diffuser(string $action, Contact $contact): void
    {
        try {
            broadcast(new MessageContactMisAJour($action, $contact));
        } catch (\Throwable $e) {
            Log::warning('Diffusion en direct du message de contact impossible (serveur Reverb arrêté ?)', [
                'contact_id' => $contact->id,
                'action'     => $action,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
