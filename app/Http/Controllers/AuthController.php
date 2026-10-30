<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use App\Models\Admin;
use App\Models\Enseignant;
use App\Models\Etudiant;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        // Validation des données
        $request->validate([
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:6|max:255'
        ]);

        $email = strtolower(trim($request->email));
        $ip = $request->ip();
        // Limite par couple IP + email : un attaquant ne bloque pas tout un réseau partagé
        $throttleKey = 'login_attempts:' . $ip . '|' . $email;

        // Protection contre les tentatives répétées
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            Log::warning('Trop de tentatives de connexion', [
                'ip' => $ip,
                'email' => $email,
                'remaining_seconds' => $seconds
            ]);

            return response()->json([
                'message' => 'Trop de tentatives de connexion. Réessayez dans ' . ceil($seconds / 60) . ' minutes.',
                'retry_after' => $seconds
            ], 429);
        }

        // Recherche de l'utilisateur
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            RateLimiter::hit($throttleKey, 60 * 15); // 15 minutes
            Log::warning('Tentative de connexion échouée', [
                'ip' => $ip,
                'email' => $email,
                'user_exists' => $user ? true : false
            ]);

            return response()->json([
                'message' => 'Identifiants incorrects'
            ], 401);
        }

        // NOUVELLE VÉRIFICATION : Compte vérifié
        if (!$user->is_verified) {
            RateLimiter::hit($throttleKey, 60 * 15); // 15 minutes
            Log::warning('Tentative de connexion avec compte non vérifié', [
                'ip' => $ip,
                'email' => $email,
                'user_id' => $user->id
            ]);

            return response()->json([
                'message' => 'Votre compte n\'est pas encore vérifié. Veuillez contacter l\'administrateur.',
                'error_type' => 'account_not_verified'
            ], 403);
        }

        // Réinitialiser les tentatives en cas de succès
        RateLimiter::clear($throttleKey);

        // Supprimer anciens tokens
        $user->tokens()->delete();
        $user->noterConnexion();

        // Générer un token avec expiration
        $token = $user->createToken('auth_token', ['*'], Carbon::now()->addHours(24))->plainTextToken;

        Log::info('Connexion réussie', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $ip,
            'role' => $user->role
        ]);

        return response()->json([
            'message' => 'Connexion réussie',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => Carbon::now()->addHours(24)->toISOString(),
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request)
    {
        // Supprimer le token actuel
        $request->user()->currentAccessToken()->delete();

        Log::info('Déconnexion', [
            'user_id' => $request->user()->id,
            'ip' => $request->ip()
        ]);

        return response()->json([
            'message' => 'Déconnexion réussie'
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $this->userPayload($request->user()),
        ]);
    }

    /**
     * POST /api/presence : l'application est ouverte (signal envoyé toutes les 45 s).
     * Ignoré pendant une consultation par l'administration : ce n'est pas la personne qui est là.
     */
    public function presence(Request $request)
    {
        $user = $request->user();
        if (!in_array('consultation', $user->currentAccessToken()->abilities ?? [], true)) {
            $user->noterActivite();
        }

        return response()->noContent();
    }

    public function refresh(Request $request)
    {
        $user = $request->user();

        // Session prolongée par la personne elle-même (pas pendant une consultation par l'administration)
        if (!in_array('consultation', $user->currentAccessToken()->abilities ?? [], true)) {
            $user->noterConnexion();
        }

        // Supprimer l'ancien token
        $request->user()->currentAccessToken()->delete();
        
        // Créer un nouveau token
        $token = $user->createToken('auth_token', ['*'], Carbon::now()->addHours(24))->plainTextToken;
        
        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => Carbon::now()->addHours(24)->toISOString(),
        ]);
    }

    private function userPayload(User $user): array
    {
        return $user->payloadSession();
    }
}
