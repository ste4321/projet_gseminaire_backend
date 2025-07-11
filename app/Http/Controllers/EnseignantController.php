<?php

namespace App\Http\Controllers;

use App\Models\Enseignant;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;


class EnseignantController extends Controller
{
    public function index()
    {
        return Enseignant::orderBy('id')->get();
    }

    public function update(Request $request, $id)
    {
        $enseignant = Enseignant::findOrFail($id);

        // Optionnel : validation (recommandé)
        $request->validate([
            'nom_prenom' => 'required|string|max:255',
            'adresse' => 'nullable|string|max:255',
            'email' => 'nullable|string|max:255',
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
            'email' => 'nullable|string|max:255',
            'telephone' => 'nullable|string|max:30',
        ]);
    
        $enseignant = Enseignant::create($request->only(['nom_prenom', 'adresse', 'email', 'telephone']));
    
        return response()->json($enseignant, 201);
    }

    public function showCession($id)
{
    $enseignant = Enseignant::with('user')->findOrFail($id);

    if ($enseignant->id_user_cession) {
        $cession = User::find($enseignant->id_user_cession);
        return response()->json(['cession' => $cession]);
    }

    return response()->json(['cession' => null]);
}

public function assignCession(Request $request, $id)
{
    $enseignant = Enseignant::findOrFail($id);

    $request->validate([
        'email' => 'required|email|unique:user_cession,email',
        'password' => 'required|min:6',
        'role' => 'required|in:prof',
    ]);

    $user = User::create([
        'email' => $request->email,
        'password' => Hash::make($request->password),
        'role' => $request->role,
        'is_verified' => true
    ]);

    $enseignant->id_user_cession = $user->id;
    $enseignant->save();

    return response()->json(['message' => 'Cession attribuée avec succès.', 'user' => $user]);
}
    
}
