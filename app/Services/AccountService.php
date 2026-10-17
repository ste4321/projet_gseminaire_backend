<?php

namespace App\Services;

use App\Models\Password;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Création des comptes de connexion (user_cession) pour les étudiants et les enseignants.
 * Point unique : étudiant (formulaire + import Excel) et enseignant passent par ici.
 */
class AccountService
{
    /** Domaine des adresses générées quand la personne n'a pas d'email */
    public const PLACEHOLDER_DOMAIN = 'sans-email.gstf.local';

    /**
     * Crée un compte désactivé (l'admin l'active depuis le Portail).
     *
     * @return array{0: User, 1: string} le compte et le mot de passe provisoire en clair
     */
    public function create(string $role, ?string $email, string $identifiant): array
    {
        $plainPassword = Str::password(10, symbols: false);
        $hash = Hash::make($plainPassword);

        $user = User::create([
            'email'       => $this->usableEmail($email, $identifiant),
            'password'    => $hash,
            'is_verified' => false,
            'role'        => $role,
        ]);

        // Conservé en attendant l'étape « comptes et mots de passe » (suppression du stockage en clair)
        Password::create([
            'id_user_cession' => $user->id,
            'password'        => $plainPassword,
            'psw_scrypt'      => $hash,
        ]);

        return [$user, $plainPassword];
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
     * Supprime le compte de connexion (et ses mots de passe, par cascade).
     */
    public function delete(?int $userId): void
    {
        if ($userId) {
            Password::where('id_user_cession', $userId)->delete();
            User::whereKey($userId)->delete();
        }
    }
}
