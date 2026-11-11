<?php

namespace App\Http\Controllers;

use App\Events\ActualiteMiseAJour;
use App\Models\Info;
use App\Services\ConfirmationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InfoController extends Controller
{
    /**
     * GET /api/infos (public) — actualités, les plus récentes d'abord.
     * - ?page=N[&per_page=6][&q=texte] : une page (site vitrine : « Voir plus » ; administration : pages et recherche) ;
     * - sans paramètre : toutes (ancien format, gardé pour les versions déjà en ligne du site).
     */
    public function index(Request $request)
    {
        $filtres = $request->validate([
            'page'     => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
            'q'        => 'nullable|string|max:100',
        ]);

        $query = Info::query()->orderByDesc('created_at')->orderByDesc('id');
        if (!isset($filtres['page'])) {
            return $query->get();
        }

        $totalTout = (clone $query)->count();
        if ($texte = trim($filtres['q'] ?? '')) {
            $motif = '%' . addcslashes($texte, '%_\\') . '%';
            $query->where(fn ($w) => $w
                ->where('titre', 'ilike', $motif)
                ->orWhere('description', 'ilike', $motif)
                ->orWhere('auteur', 'ilike', $motif));
        }
        $page = $query->paginate($filtres['per_page'] ?? 6);

        return response()->json([
            'data'       => $page->items(),
            'page'       => $page->currentPage(),
            'pages'      => $page->lastPage(),
            'total'      => $page->total(),
            'total_tout' => $totalTout,
        ]);
    }

    /** GET /api/infos/{id} (public) — une actualité : lien partagé (#actualite-12) vers une actualité ancienne, hors des pages chargées */
    public function show($id)
    {
        return Info::findOrFail($id);
    }

    /** Publication : exige le code de confirmation émis par POST /api/confirmations (action publier_actualite) */
    public function store(Request $request, ConfirmationService $confirmations)
    {
        $donnees = $this->validated($request);
        $confirmations->exiger($request->user(), 'publier_actualite', $request->input('code_confirmation'));

        $info = Info::create($donnees);
        $this->diffuser(ActualiteMiseAJour::CREEE, $info);

        return response()->json($info, 201);
    }

    public function update(Request $request, $id)
    {
        $info = Info::findOrFail($id);
        $info->update($this->validated($request));
        $this->diffuser(ActualiteMiseAJour::MODIFIEE, $info);

        return $info;
    }

    public function destroy($id)
    {
        $info = Info::findOrFail($id);
        $info->delete();
        $this->diffuser(ActualiteMiseAJour::SUPPRIMEE, $info);

        return response()->json(['message' => 'Supprimé avec succès']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'auteur'      => ['required', 'string', 'max:255'],
            'titre'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
        ]);
    }

    /**
     * Prévient le site vitrine en direct. Si le serveur WebSocket est arrêté,
     * l'actualité est quand même enregistrée : le site la récupérera à son prochain chargement.
     */
    private function diffuser(string $action, Info $info): void
    {
        try {
            broadcast(new ActualiteMiseAJour($action, $info));
        } catch (\Throwable $e) {
            Log::warning('Diffusion en direct de l\'actualité impossible (serveur Reverb arrêté ?)', [
                'info_id' => $info->id,
                'action'  => $action,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
