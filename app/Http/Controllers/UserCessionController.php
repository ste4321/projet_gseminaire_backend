<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Comptes de connexion (page « Comptes et accès ») : liste par rôle, activation, statistiques.
 * Les erreurs inattendues sont journalisées par le gestionnaire d'exceptions de Laravel.
 */
class UserCessionController extends Controller
{
    public const ROLES = ['admin', 'prof', 'etudiant'];

    /** GET /api/user-cessions?role=admin|prof|etudiant : comptes avec le nom et le téléphone de leur profil */
    public function index(Request $request)
    {
        ['role' => $role] = $request->validate(['role' => ['nullable', Rule::in(self::ROLES)]]) + ['role' => null];

        // Profils chargés en 3 requêtes groupées (au lieu d'une requête par utilisateur)
        $users = User::select(['id', 'email', 'role', 'is_verified', 'must_change_password', 'created_at', 'updated_at'])
            ->when($role, fn ($q) => $q->where('role', $role))
            ->with([
                'admin:id,id_user_cession,nom_prenom,telephone',
                'enseignant:id,id_user_cession,nom_prenom,telephone',
                'etudiants:id,id_user_cession,nom_prenom,telephone',
            ])
            ->orderByDesc('created_at')
            ->get();

        return response()->json($users->map(function (User $u) {
            $profil = match ($u->role) {
                'admin'    => $u->admin,
                'prof'     => $u->enseignant,
                'etudiant' => $u->etudiants,
                default    => null,
            };

            return [
                'id'                   => $u->id,
                'id_user_cession'      => $u->id,
                'email'                => $u->email,
                'role'                 => $u->role,
                'is_verified'          => (bool) $u->is_verified,
                'must_change_password' => (bool) $u->must_change_password,
                'nom_prenom'           => $profil?->nom_prenom,
                'telephone'            => $profil?->telephone,
                'created_at'           => $u->created_at,
                'updated_at'           => $u->updated_at,
            ];
        }));
    }

    /** PATCH /api/user-cessions/{id}/toggle : active ou désactive un compte (jamais le sien) */
    public function toggle(Request $request, $id)
    {
        if ((int) $id === $request->user()->id) {
            return response()->json(['message' => 'Vous ne pouvez pas désactiver votre propre compte.'], 422);
        }

        $user = User::findOrFail($id);
        $user->is_verified = !$user->is_verified;
        $user->save();
        if (!$user->is_verified) {
            $user->tokens()->delete(); // ses sessions ouvertes sont fermées
        }

        return response()->json([
            'is_verified' => (bool) $user->is_verified,
            'message'     => $user->is_verified ? 'Utilisateur activé' : 'Utilisateur désactivé',
        ]);
    }

    /** PATCH /api/user-cessions/bulk-toggle : active ou désactive plusieurs comptes */
    public function bulkToggle(Request $request)
    {
        $request->validate([
            'user_ids'    => 'required|array|min:1',
            'user_ids.*'  => 'required|integer|exists:user_cession,id',
            'is_verified' => 'required|boolean',
        ]);

        // L'admin connecté est toujours exclu : il ne peut pas se bloquer lui-même
        $userIds = array_values(array_diff($request->input('user_ids'), [$request->user()->id]));
        $isVerified = $request->boolean('is_verified');

        $updatedCount = User::whereIn('id', $userIds)->update(['is_verified' => $isVerified]);
        if (!$isVerified) {
            \Laravel\Sanctum\PersonalAccessToken::where('tokenable_type', User::class)->whereIn('tokenable_id', $userIds)->delete();
        }

        return response()->json([
            'success'       => true,
            'message'       => sprintf('%d compte%s %s.', $updatedCount, $updatedCount > 1 ? 's' : '', $isVerified
                ? ($updatedCount > 1 ? 'activés' : 'activé')
                : ($updatedCount > 1 ? 'désactivés' : 'désactivé')),
            'updated_count' => $updatedCount,
            'is_verified'   => $isVerified,
        ]);
    }

    /**
     * GET /api/user-cessions/stats : nombre de comptes actifs / inactifs par rôle
     */
    public function stats()
    {
        $rows = User::selectRaw('role, is_verified, COUNT(*) as total')
            ->groupBy('role', 'is_verified')
            ->get();

        $stats = [];
        foreach (self::ROLES as $role) {
            $actifs = (int) $rows->where('role', $role)->where('is_verified', true)->sum('total');
            $inactifs = (int) $rows->where('role', $role)->where('is_verified', false)->sum('total');
            $stats[$role] = ['actifs' => $actifs, 'inactifs' => $inactifs, 'total' => $actifs + $inactifs];
        }

        return response()->json($stats);
    }
}
