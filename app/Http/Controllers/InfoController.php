<?php

namespace App\Http\Controllers;

use App\Events\ActualiteMiseAJour;
use App\Models\Info;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InfoController extends Controller
{
    public function index()
    {
        return Info::latest()->get();
    }

    public function store(Request $request)
    {
        $info = Info::create($this->validated($request));
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
