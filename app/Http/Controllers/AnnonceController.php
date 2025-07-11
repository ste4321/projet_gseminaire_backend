<?php

namespace App\Http\Controllers;

use App\Models\Annonce;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AnnonceController extends Controller
{
    // 🔹 Liste toutes les annonces avec relations
    public function index()
    {
        $annonces = Annonce::with(['niveau', 'anneeAca'])->orderByDesc('created_at')->get();
        return response()->json($annonces);
    }

    // 🔹 Créer une annonce
    public function store(Request $request)
    {
        $validated = $request->validate([
            'titre' => 'required|string|max:255',
            'description' => 'nullable|string',
            'fichier' => 'nullable|file|mimes:zip,doc,docx,pdf',
            'id_annee_aca' => 'required|exists:annee_aca,id',
            'id_niveau' => 'required|exists:niveaux,id',
            'expediteur' => 'required|string|max:255',
        ]);

        // Gestion du fichier joint
        if ($request->hasFile('fichier')) {
            $path = $request->file('fichier')->store('annonces', 'public');
            $validated['fichier'] = asset('storage/' . $path);
        }

        $annonce = Annonce::create($validated);
        return response()->json($annonce, 201);
    }

    // 🔹 Affiche une seule annonce
    public function show($id)
    {
        $annonce = Annonce::with(['niveau', 'anneeAca'])->findOrFail($id);
        return response()->json($annonce);
    }

    // 🔹 Modifier une annonce
    public function update(Request $request, $id)
    {
        $annonce = Annonce::findOrFail($id);

        $validated = $request->validate([
            'titre' => 'required|string|max:255',
            'description' => 'nullable|string',
            'fichier' => 'nullable|file|mimes:zip,doc,docx,pdf',
            'id_annee_aca' => 'required|exists:annee_aca,id',
            'id_niveau' => 'required|exists:niveaux,id',
            'expediteur' => 'required|string|max:255',
        ]);

        if ($request->hasFile('fichier')) {
            // Optionnel : supprimer l'ancien fichier
            if ($annonce->fichier && str_starts_with($annonce->fichier, asset('storage/'))) {
                $relativePath = str_replace(asset('storage/'), '', $annonce->fichier);
                Storage::disk('public')->delete($relativePath);
            }

            $path = $request->file('fichier')->store('annonces', 'public');
            $validated['fichier'] = asset('storage/' . $path);
        }

        $annonce->update($validated);
        return response()->json($annonce);
    }

    // 🔹 Supprimer une annonce
    public function destroy($id)
    {
        $annonce = Annonce::findOrFail($id);

        // Supprimer le fichier s’il existe
        if ($annonce->fichier && str_starts_with($annonce->fichier, asset('storage/'))) {
            $relativePath = str_replace(asset('storage/'), '', $annonce->fichier);
            Storage::disk('public')->delete($relativePath);
        }

        $annonce->delete();
        return response()->json(['message' => 'Annonce supprimée avec succès.'], 204);
    }
}
