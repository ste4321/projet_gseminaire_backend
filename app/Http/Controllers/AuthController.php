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

        $email = $request->email;
        $ip = $request->ip();
        $throttleKey = 'login_attempts:' . $ip;

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
        $user = User::where('email', $email)->first();

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

        // Réinitialiser les tentatives en cas de succès
        RateLimiter::clear($throttleKey);

        // Supprimer anciens tokens
        $user->tokens()->delete();

        // Générer un token avec expiration
        $token = $user->createToken('auth_token', ['*'], Carbon::now()->addHours(24))->plainTextToken;

        // Mettre à jour les infos de connexion
        $user->update([
            'last_login_at' => Carbon::now(),
            'last_login_ip' => $ip
        ]);

        // Obtenir le nom_prenom selon le rôle
        $nomPrenom = null;
        switch ($user->role) {
            case 'admin':
                $admin = Admin::where('id_user_cession', $user->id)->first();
                $nomPrenom = $admin?->nom_prenom;
                break;

            case 'prof':
                $prof = Enseignant::where('id_user_cession', $user->id)->first();
                $nomPrenom = $prof?->nom_prenom;
                break;

            case 'etudiant':
                $etudiant = Etudiant::where('id_user_cession', $user->id)->first();
                $nomPrenom = $etudiant?->nom_prenom;
                break;
        }

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
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'role' => $user->role,
                'nom_prenom' => $nomPrenom,
                'last_login_at' => $user->last_login_at?->toISOString(),
            ]
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
            'user' => [
                'id' => $request->user()->id,
                'email' => $request->user()->email,
                'role' => $request->user()->role,
                'name' => $request->user()->name,
                'last_login_at' => $request->user()->last_login_at?->toISOString(),
            ]
        ]);
    }

    public function refresh(Request $request)
    {
        $user = $request->user();
        
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
}