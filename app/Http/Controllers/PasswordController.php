<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    public function __construct(private AccountService $accounts)
    {
    }

    /**
     * POST /api/password/change : l'utilisateur connecté remplace son mot de passe
     * (obligatoire après un mot de passe provisoire).
     */
    public function change(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'confirmed', 'max:255', Password::defaults()],
        ], [
            'password.confirmed' => 'La confirmation ne correspond pas au nouveau mot de passe.',
            'password.min'       => 'Le mot de passe doit contenir au moins :min caractères.',
            'password.letters'   => 'Le mot de passe doit contenir au moins une lettre.',
            'password.numbers'   => 'Le mot de passe doit contenir au moins un chiffre.',
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Le mot de passe actuel est incorrect.',
                'errors'  => ['current_password' => ['Le mot de passe actuel est incorrect.']],
            ], 422);
        }

        if (Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => "Le nouveau mot de passe doit être différent de l'actuel.",
                'errors'  => ['password' => ["Le nouveau mot de passe doit être différent de l'actuel."]],
            ], 422);
        }

        $this->accounts->changePassword($user, $validated['password'], $user->currentAccessToken()?->id);
        Log::info('Mot de passe changé', ['user_id' => $user->id, 'ip' => $request->ip()]);

        return response()->json(['message' => 'Mot de passe modifié avec succès.']);
    }

    /**
     * POST /api/user-cessions/{id}/reset-password : l'admin génère un nouveau mot de passe provisoire.
     * Il est renvoyé une seule fois ; l'utilisateur devra le changer à sa connexion.
     */
    public function reset(Request $request, $id)
    {
        $user = User::findOrFail($id);

        if ($user->id === $request->user()->id) {
            return response()->json([
                'message' => 'Utilisez « Changer mon mot de passe » pour votre propre compte.',
            ], 422);
        }

        $motDePasse = $this->accounts->resetPassword($user);
        Log::info('Mot de passe réinitialisé par un admin', ['user_id' => $user->id, 'admin_id' => $request->user()->id]);

        return response()->json([
            'message'      => 'Mot de passe réinitialisé.',
            'email'        => $user->email,
            'mot_de_passe' => $motDePasse,
        ]);
    }
}
