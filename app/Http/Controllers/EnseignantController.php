<?php

namespace App\Http\Controllers;

use App\Models\Enseignant;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Password;

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
        try {
            DB::beginTransaction();

            // 1. Récupérer l'enseignant
            $enseignant = Enseignant::findOrFail($id);

            // 2. Supprimer les passwords liés
            Password::where('id_user_cession', $enseignant->id_user_cession)->delete();

            // 3. Supprimer le user_cession lié
            User::where('id', $enseignant->id_user_cession)->delete();

            // 4. Supprimer l'enseignant
            $enseignant->delete();

            DB::commit();

            return response()->json(['message' => 'Enseignant et ses données associées supprimés avec succès.']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Erreur lors de la suppression.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nom_prenom' => 'required|string|max:255',
            'adresse'    => 'nullable|string|max:255',
            'email'      => 'nullable|email|max:255',
            'telephone'  => 'nullable|string|max:30',
        ]);

        try {
            DB::beginTransaction();

            // 1️⃣ Générer mot de passe aléatoire
            $randomPassword = Str::random(6);
            $hashedPassword = Hash::make($randomPassword);

            // 2️⃣ Créer un utilisateur dans user_cession
            $user = User::create([
                'email'       => $validated['email'] ?? Str::slug($validated['nom_prenom']).'@fake.email',
                'password'    => $hashedPassword,
                'is_verified' => false,
                'role'        => 'prof',
            ]);

            // 3️⃣ Enregistrer dans la table passwords
            Password::create([
                'id_user_cession' => $user->id,
                'password'        => $randomPassword,
                'psw_scrypt'      => $hashedPassword,
            ]);

            // 4️⃣ Créer l'enseignant dans corps_enseignant
            $enseignant = Enseignant::create([
                'nom_prenom'      => $validated['nom_prenom'],
                'adresse'         => $validated['adresse'] ?? null,
                'email'           => $validated['email'] ?? null,
                'telephone'       => $validated['telephone'] ?? null,
                'id_user_cession' => $user->id,
            ]);

            DB::commit();

            return response()->json([
                'message'      => "Enseignant ajouté avec succès.",
                'enseignant'   => $enseignant,
                'user'         => $user,
                'mot_de_passe' => $randomPassword 
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => "Erreur lors de l'ajout de l'enseignant.",
                'error'   => $e->getMessage(),
            ], 500);
        }
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
