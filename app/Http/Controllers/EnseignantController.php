<?php

namespace App\Http\Controllers;

use App\Models\Enseignant;
use Illuminate\Http\Request;

class EnseignantController extends Controller
{
    public function index()
    {
        return Enseignant::all();
    }

    public function update(Request $request, $id)
    {
        $enseignant = Enseignant::findOrFail($id);

        // Optionnel : validation (recommandé)
        $request->validate([
            'nom_prenom' => 'required|string|max:255',
            'adresse' => 'nullable|string|max:255',
            'mail' => 'nullable|email|max:255',
            'telephone' => 'nullable|string|max:20',
        ]);

        $enseignant->update($request->only(['nom_prenom', 'adresse', 'mail', 'telephone']));

        return response()->json(['message' => 'Enseignant mis à jour avec succès']);
    }



    public function destroy($id)
    {
        Enseignant::destroy($id);
        return response()->json(['message' => 'Supprimé']);
    }
    
    public function store(Request $request)
    {
        $request->validate([
            'nom_prenom' => 'required|string|max:255',
            'adresse' => 'nullable|string|max:255',
            'mail' => 'nullable|email|max:255',
            'telephone' => 'nullable|string|max:30',
        ]);
    
        $enseignant = Enseignant::create($request->only(['nom_prenom', 'adresse', 'mail', 'telephone']));
    
        return response()->json($enseignant, 201);
    }
    
}
