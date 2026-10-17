<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;

class UserCessionController extends Controller
{
    /**
     * GET /api/user-cessions?role=admin|prof|etudiant
     * Version avec meilleure gestion d'erreurs
     */
    public function index(Request $request)
    {
        try {
            $role = $request->query('role');
            
            Log::info("Fetching users for role: " . ($role ?? 'all'));

            // Version corrigée avec le bon nom de table
            $query = User::select([
                'user_cession.id',
                'user_cession.email', 
                'user_cession.role',
                'user_cession.is_verified',
                'user_cession.created_at',
                'user_cession.updated_at'
            ]);

            if ($role) {
                $query->where('user_cession.role', $role);
            }

            // Profils chargés en 3 requêtes groupées (au lieu d'une requête par utilisateur)
            $users = $query->with([
                'admin:id,id_user_cession,nom_prenom,telephone',
                'enseignant:id,id_user_cession,nom_prenom,telephone',
                'etudiants:id,id_user_cession,nom_prenom,telephone',
            ])->orderBy('user_cession.created_at', 'desc')->get();

            $result = $users->map(function ($u) {
                $profile = match ($u->role) {
                    'admin'    => $u->admin,
                    'prof'     => $u->enseignant,
                    'etudiant' => $u->etudiants,
                    default    => null,
                };

                return [
                    'id' => $u->id,
                    'id_user_cession' => $u->id,
                    'email' => $u->email,
                    'role' => $u->role,
                    'is_verified' => (bool) $u->is_verified,
                    'nom_prenom' => $profile?->nom_prenom,
                    'telephone' => $profile?->telephone,
                    'created_at' => $u->created_at,
                    'updated_at' => $u->updated_at,
                ];
            });

            return response()->json($result);

        } catch (\Exception $e) {
            Log::error("Erreur dans UserCessionController@index: " . $e->getMessage());
            Log::error("Stack trace: " . $e->getTraceAsString());
            
            return response()->json([
                'error' => 'Erreur lors de la récupération des utilisateurs',
                'message' => 'Erreur interne du serveur'
            ], 500);
        }
    }

    /**
     * PATCH /api/user-cessions/{id}/toggle
     */
    public function toggle(Request $request, $id)
    {
        if ((int) $id === $request->user()->id) {
            return response()->json(['message' => 'Vous ne pouvez pas désactiver votre propre compte.'], 422);
        }

        try {
            $user = User::findOrFail($id);
            $user->is_verified = !$user->is_verified;
            $user->save();

            Log::info("User {$id} toggled to " . ($user->is_verified ? 'verified' : 'unverified'));

            return response()->json([
                'is_verified' => (bool)$user->is_verified,
                'message' => $user->is_verified ? 'Utilisateur activé' : 'Utilisateur désactivé'
            ]);

        } catch (\Exception $e) {
            Log::error("Erreur dans UserCessionController@toggle: " . $e->getMessage());
            
            return response()->json([
                'error' => 'Erreur lors de la mise à jour',
                'message' => 'Erreur interne du serveur'
            ], 500);
        }
    }

    /**
     * PATCH /api/user-cessions/bulk-toggle
     */
    public function bulkToggle(Request $request)
    {
        try {
            $request->validate([
                'user_ids' => 'required|array|min:1',
                'user_ids.*' => 'required|integer|exists:user_cession,id',
                'is_verified' => 'required|boolean'
            ]);

            // L'admin connecté est toujours exclu : il ne peut pas se bloquer lui-même
            $userIds = array_values(array_diff($request->input('user_ids'), [$request->user()->id]));
            $isVerified = $request->boolean('is_verified');

            Log::info("Bulk toggle for users: " . implode(',', $userIds) . " to " . ($isVerified ? 'verified' : 'unverified'));

            DB::beginTransaction();
            
            $updatedCount = User::whereIn('id', $userIds)
                               ->update(['is_verified' => $isVerified]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => sprintf(
                    '%d utilisateur(s) %s avec succès',
                    $updatedCount,
                    $isVerified ? 'activé(s)' : 'désactivé(s)'
                ),
                'updated_count' => $updatedCount,
                'is_verified' => $isVerified
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Erreur dans UserCessionController@bulkToggle: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour en lot',
                'error' => 'Erreur interne du serveur'
            ], 500);
        }
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
        foreach (['admin', 'prof', 'etudiant'] as $role) {
            $actifs = (int) $rows->where('role', $role)->where('is_verified', true)->sum('total');
            $inactifs = (int) $rows->where('role', $role)->where('is_verified', false)->sum('total');
            $stats[$role] = ['actifs' => $actifs, 'inactifs' => $inactifs, 'total' => $actifs + $inactifs];
        }

        return response()->json($stats);
    }
}
