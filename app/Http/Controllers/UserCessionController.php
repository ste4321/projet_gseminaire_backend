<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Enseignant;
use App\Models\Etudiant;
use App\Models\User;
use App\Services\AccountService;
use App\Services\ConfirmationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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
        $users = User::select(['id', 'email', 'role', 'is_verified', 'must_change_password', 'provisoire_depuis', 'derniere_connexion_at', 'derniere_activite_at', 'created_at', 'updated_at'])
            ->avecPresence()
            ->when($role, fn ($q) => $q->where('role', $role))
            ->with([
                'admin:id,id_user_cession,nom_prenom,telephone',
                'enseignant:id,id_user_cession,nom_prenom,telephone',
                'etudiants:id,id_user_cession,nom_prenom,telephone,matricule',
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
                'provisoire_depuis'    => $u->provisoire_depuis,
                'derniere_connexion_at' => $u->derniere_connexion_at,
                'derniere_activite_at' => $u->derniere_activite_at,
                'en_ligne'             => $u->estEnLigne(),
                'nom_prenom'           => $profil?->nom_prenom,
                'telephone'            => $profil?->telephone,
                'matricule'            => $u->role === 'etudiant' ? $profil?->matricule : null,
                'created_at'           => $u->created_at,
                'updated_at'           => $u->updated_at,
            ];
        }));
    }

    /**
     * POST /api/user-cessions/admins : nouvel administrateur, depuis l'onglet Administrateurs de « Comptes et accès ».
     * Exige le mot de passe de l'administrateur connecté (5 essais, comme à la connexion) : une session restée
     * ouverte ne suffit pas pour donner ces droits à quelqu'un. Le compte est actif tout de suite,
     * avec un mot de passe provisoire affiché une seule fois.
     */
    public function storeAdmin(Request $request, AccountService $accounts)
    {
        $validated = $request->validate([
            'nom_prenom'       => ['required', 'string', 'max:255'],
            'email'            => ['required', 'email:filter', 'max:255'],
            'telephone'        => ['nullable', 'string', 'max:20'],
            'current_password' => ['required', 'string'],
        ], [
            'nom_prenom.required'       => 'Le nom est obligatoire.',
            'email.required'            => "L'email est obligatoire : il sert d'identifiant de connexion.",
            'email.email'               => "Cet email n'est pas valide.",
            'current_password.required' => 'Saisissez votre mot de passe pour confirmer.',
        ]);

        $demandeur = $request->user();
        $cleEssais = 'confirmation_admin:' . $demandeur->id;

        if (RateLimiter::tooManyAttempts($cleEssais, 5)) {
            $minutes = (int) ceil(RateLimiter::availableIn($cleEssais) / 60);

            return response()->json(['message' => "Trop d'essais de mot de passe. Réessayez dans {$minutes} minute" . ($minutes > 1 ? 's' : '') . '.'], 429);
        }

        if (!Hash::check($validated['current_password'], $demandeur->password)) {
            RateLimiter::hit($cleEssais, 60 * 15);

            return response()->json([
                'message' => 'Mot de passe incorrect.',
                'errors'  => ['current_password' => ['Mot de passe incorrect.']],
            ], 422);
        }
        RateLimiter::clear($cleEssais);

        if ($accounts->emailTaken($validated['email'])) {
            return response()->json([
                'message' => 'Cet email est déjà utilisé par un autre compte.',
                'errors'  => ['email' => ['Cet email est déjà utilisé par un autre compte.']],
            ], 422);
        }

        try {
            [$admin, $user, $motDePasse] = DB::transaction(function () use ($accounts, $validated) {
                [$user, $motDePasse] = $accounts->create('admin', $validated['email'], $validated['nom_prenom']);
                $user->update(['is_verified' => true]);

                $admin = Admin::create([
                    'id_user_cession' => $user->id,
                    'nom_prenom'      => $validated['nom_prenom'],
                    'email'           => $user->email,
                    'telephone'       => $validated['telephone'] ?? null,
                ]);

                return [$admin, $user, $motDePasse];
            });
        } catch (\Throwable $e) {
            return $this->serverError("Erreur lors de l'ajout de l'administrateur.", $e);
        }

        Log::info('Administrateur créé', ['par' => $demandeur->id, 'compte' => $user->id]);

        return response()->json([
            'message'      => 'Administrateur ajouté avec succès.',
            'admin'        => $admin,
            'user'         => $user,
            'mot_de_passe' => $motDePasse, // affiché une seule fois
        ], 201);
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
     * Suppression de comptes de connexion (ex. étudiant exclu), depuis la sélection de la page « Comptes et accès ».
     * Exige le code de confirmation (action supprimer_compte), un seul pour toute la sélection. Seul l'accès disparaît :
     * le dossier de l'étudiant (notes, bulletins, parcours) ou la fiche de l'enseignant est conservé, détaché du compte.
     * (Les clés étrangères des profils sont en cascade : sans ce détachement, le dossier et les notes seraient effacés.)
     * Un nouveau compte peut être recréé plus tard, par exemple par un nouvel import des étudiants.
     */

    /** DELETE /api/user-cessions/{id} */
    public function destroy(Request $request, ConfirmationService $confirmations, $id)
    {
        return $this->supprimer($request, $confirmations, [User::findOrFail($id)->id]);
    }

    /** POST /api/user-cessions/bulk-delete { user_ids: [...], code_confirmation } */
    public function bulkDestroy(Request $request, ConfirmationService $confirmations)
    {
        $validated = $request->validate([
            'user_ids'   => ['required', 'array', 'min:1', 'max:500'],
            'user_ids.*' => ['integer', 'exists:user_cession,id'],
        ], [
            'user_ids.required' => 'Aucun compte sélectionné.',
        ]);

        return $this->supprimer($request, $confirmations, array_values(array_unique($validated['user_ids'])));
    }

    private function supprimer(Request $request, ConfirmationService $confirmations, array $ids)
    {
        $moi = $request->user()->id;
        if (in_array($moi, $ids, true)) {
            return response()->json(['message' => 'Vous ne pouvez pas supprimer votre propre compte.'], 422);
        }
        $adminsRestants = User::where('role', 'admin')->where('is_verified', true)->whereNotIn('id', $ids)->exists();
        if (!$adminsRestants && User::whereIn('id', $ids)->where('role', 'admin')->exists()) {
            return response()->json(['message' => 'Impossible de supprimer le dernier administrateur actif.'], 422);
        }
        $confirmations->exiger($request->user(), 'supprimer_compte', $request->input('code_confirmation'));

        $comptes = User::whereIn('id', $ids)->get();
        $noms = DB::transaction(function () use ($comptes, $ids) {
            $noms = Etudiant::whereIn('id_user_cession', $ids)->pluck('nom_prenom', 'id_user_cession')->all()
                + Enseignant::whereIn('id_user_cession', $ids)->pluck('nom_prenom', 'id_user_cession')->all()
                + Admin::whereIn('id_user_cession', $ids)->pluck('nom_prenom', 'id_user_cession')->all();

            // Dossiers conservés, détachés des comptes
            Etudiant::whereIn('id_user_cession', $ids)->update(['id_user_cession' => null]);
            Enseignant::whereIn('id_user_cession', $ids)->update(['id_user_cession' => null]);

            foreach ($comptes as $compte) {
                $compte->tokens()->delete(); // sessions ouvertes fermées
                $compte->delete(); // un profil administrateur (simple fiche) part avec le compte
            }

            return $noms;
        });

        // Ce qui reste dépend du rôle : un enseignant n'a ni notes ni bulletins, seulement sa fiche
        $plusieurs = $comptes->count() > 1;
        $conserve = '';
        if ($comptes->contains('role', 'etudiant')) {
            $conserve .= $plusieurs ? ' Les dossiers (notes, bulletins, parcours) sont conservés.' : ' Son dossier (notes, bulletins, parcours) est conservé.';
        }
        if ($comptes->contains('role', 'prof')) {
            $conserve .= $plusieurs ? ' Les fiches enseignant sont conservées.' : ' Sa fiche enseignant est conservée.';
        }
        $message = $plusieurs
            ? "{$comptes->count()} comptes supprimés.{$conserve}"
            : 'Compte de ' . ($noms[$comptes->first()->id] ?? $comptes->first()->email) . " supprimé.{$conserve}";

        return response()->json(['message' => $message, 'supprimes' => $comptes->pluck('id')]);
    }

    /**
     * GET /api/user-cessions/presence?role=… : statut de présence seul, relu toutes les 30 s par la page
     * « Comptes et accès » (bien plus léger que la liste complète)
     */
    public function presence(Request $request)
    {
        ['role' => $role] = $request->validate(['role' => ['nullable', Rule::in(self::ROLES)]]) + ['role' => null];

        return response()->json(
            User::select(['id', 'derniere_activite_at', 'derniere_connexion_at'])
                ->avecPresence()
                ->when($role, fn ($q) => $q->where('role', $role))
                ->get()
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'en_ligne' => $u->estEnLigne(),
                    'derniere_activite_at' => $u->derniere_activite_at,
                    'derniere_connexion_at' => $u->derniere_connexion_at,
                ])
        );
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
