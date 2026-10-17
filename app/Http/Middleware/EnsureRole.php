<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restreint une route à un ou plusieurs rôles.
 * Usage : ->middleware('role:admin') ou ->middleware('role:admin,prof')
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        if (!$user->is_verified) {
            return response()->json(['message' => 'Compte désactivé.'], 403);
        }

        // Mot de passe provisoire : seul le changement de mot de passe est autorisé
        if ($user->must_change_password) {
            return response()->json([
                'message'    => 'Vous devez changer votre mot de passe provisoire avant de continuer.',
                'error_type' => 'password_change_required',
            ], 403);
        }

        if (!in_array($user->role, $roles, true)) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        return $next($request);
    }
}
