<?php

namespace App\Http\Controllers;

use App\Models\Annonce;
use App\Models\Enseignant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AnnonceController extends Controller
{
    public function index()
    {
        return Annonce::all();
    }

    public function store(Request $request)
    {
        $isAdmin = $request->input('expediteur') === 'admin';

        $rules = [
            'titre' => 'required|string',
            'description' => 'nullable|string',
            'fichier' => 'nullable|file|mimes:zip,doc,docx,pdf|max:5120',
        ];

        if (!$isAdmin) {
            $rules['enseignant_id'] = 'required|exists:corps_enseignant,id';
        }

        $validated = $request->validate($rules);

        $fichierPath = null;
        if ($request->hasFile('fichier')) {
            $fichierPath = $request->file('fichier')->store('annonces', 'public');
        }

        if ($isAdmin) {
            $expediteur = 'admin';
        } else {
            $enseignant = \App\Models\Enseignant::find($request->enseignant_id);
            if (!$enseignant) {
                return response()->json(['error' => 'Enseignant introuvable'], 404);
            }
            $expediteur = $enseignant->nom_prenom;
        }

        $annonce = \App\Models\Annonce::create([
            'titre' => $request->titre,
            'expediteur' => $expediteur,
            'description' => $request->description,
            'fichier' => $fichierPath,
        ]);

        return response()->json($annonce, 201);
    }

    public function update(Request $request, $id)
    {
        $annonce = Annonce::findOrFail($id);

        $request->validate([
            'titre' => 'required|string|max:255',
            'enseignant_id' => 'required|exists:enseignants,id',
            'description' => 'nullable|string',
            'fichier' => 'nullable|file|mimes:zip,doc,docx,pdf|max:5120',
        ]);

        $enseignant = Enseignant::find($request->enseignant_id);
        if (!$enseignant) {
            return response()->json(['error' => 'Enseignant introuvable'], 404);
        }

        if ($request->hasFile('fichier')) {
            if ($annonce->fichier && Storage::disk('public')->exists($annonce->fichier)) {
                Storage::disk('public')->delete($annonce->fichier);
            }
            $annonce->fichier = $request->file('fichier')->store('annonces', 'public');
        }

        $annonce->update([
            'titre' => $request->titre,
            'expediteur' => $enseignant->nom_prenom, // ✅ mise à jour du nom
            'description' => $request->description,
        ]);

        return response()->json($annonce);
    }

    public function destroy($id)
    {
        $annonce = Annonce::findOrFail($id);

        if ($annonce->fichier && Storage::disk('public')->exists($annonce->fichier)) {
            Storage::disk('public')->delete($annonce->fichier);
        }

        $annonce->delete();

        return response()->json(['message' => 'Annonce supprimée avec succès']);
    }
}
