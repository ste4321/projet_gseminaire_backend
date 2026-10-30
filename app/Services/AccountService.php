<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Création des comptes de connexion (user_cession) pour les étudiants, les enseignants et les administrateurs.
 * Point unique : étudiant (formulaire + import Excel), enseignant, administrateur, réinitialisation par l'admin.
 *
 * Aucun mot de passe n'est conservé en clair : le mot de passe provisoire est renvoyé
 * une seule fois à l'admin, puis l'utilisateur doit le remplacer à sa première connexion.
 */
class AccountService
{
    /** Domaine des adresses générées quand la personne n'a pas d'email */
    public const PLACEHOLDER_DOMAIN = 'sans-email.gstf.local';

    /** Caractères sans ambiguïté à la lecture (pas de 0/O, 1/l/I) : le mot de passe est souvent recopié à la main */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Crée un compte désactivé (l'admin l'active depuis le Portail) avec un mot de passe provisoire.
     *
     * @return array{0: User, 1: string} le compte et le mot de passe provisoire en clair
     */
    public function create(string $role, ?string $email, string $identifiant): array
    {
        $plainPassword = $this->provisionalPassword();

        $user = User::create([
            'email'                => $this->usableEmail($email, $identifiant),
            'password'             => Hash::make($plainPassword),
            'is_verified'          => false,
            'role'                 => $role,
            'must_change_password' => true,
        ]);

        return [$user, $plainPassword];
    }

    /**
     * Nouveau mot de passe provisoire (mot de passe oublié) : les sessions ouvertes sont fermées.
     */
    public function resetPassword(User $user): string
    {
        $plainPassword = $this->provisionalPassword();

        $user->forceFill([
            'password'             => Hash::make($plainPassword),
            'must_change_password' => true,
        ])->save();

        $user->tokens()->delete();

        return $plainPassword;
    }

    /**
     * Mot de passe choisi par l'utilisateur : il devient définitif.
     * Les autres sessions sont fermées (sauf celle en cours, si précisée).
     */
    public function changePassword(User $user, string $newPassword, ?int $keepTokenId = null): void
    {
        $user->forceFill([
            'password'             => Hash::make($newPassword),
            'must_change_password' => false,
            'password_changed_at'  => now(),
        ])->save();

        $user->tokens()->when($keepTokenId, fn ($q) => $q->where('id', '!=', $keepTokenId))->delete();
    }

    /** 12 caractères, au moins une minuscule, une majuscule et un chiffre */
    public function provisionalPassword(): string
    {
        do {
            $password = '';
            for ($i = 0; $i < 12; $i++) {
                $password .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password));

        return $password;
    }

    /**
     * Email réel s'il est libre, sinon adresse technique unique dérivée de l'identifiant
     * (matricule ou nom), pour ne jamais casser la contrainte d'unicité.
     */
    public function usableEmail(?string $email, string $identifiant): string
    {
        $email = $email ? strtolower(trim($email)) : null;

        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL) && !$this->emailTaken($email)) {
            return $email;
        }

        $base = Str::slug($identifiant) ?: 'utilisateur';
        $candidate = "{$base}@" . self::PLACEHOLDER_DOMAIN;
        $i = 2;
        while ($this->emailTaken($candidate)) {
            $candidate = "{$base}-{$i}@" . self::PLACEHOLDER_DOMAIN;
            $i++;
        }

        return $candidate;
    }

    public function emailTaken(string $email, ?int $exceptUserId = null): bool
    {
        return User::whereRaw('LOWER(email) = ?', [strtolower($email)])
            ->when($exceptUserId, fn ($q) => $q->where('id', '!=', $exceptUserId))
            ->exists();
    }

    /**
     * Supprime le compte de connexion (et ses jetons).
     */
    public function delete(?int $userId): void
    {
        if ($userId && $user = User::find($userId)) {
            $user->tokens()->delete();
            $user->delete();
        }
    }
}
