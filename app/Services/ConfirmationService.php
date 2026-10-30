<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Codes de confirmation pour les actions sensibles (publier les notes, publier une actualité, réinscrire les étudiants).
 *
 * Le serveur émet un code court, l'interface l'affiche et l'utilisateur doit le recopier :
 * une publication ne part jamais sur un simple clic. Le code est lié à l'utilisateur
 * et à l'action, valable CODE_TTL secondes, et ne sert qu'une fois
 * (un code erroné est aussi consommé : il faut en demander un nouveau).
 */
class ConfirmationService
{
    public const ACTIONS = ['publier_notes', 'publier_actualite', 'reinscrire', 'supprimer_compte'];
    public const CODE_TTL = 300;
    private const LONGUEUR = 6;
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // sans 0/O, 1/I/L

    public function emettre(User $user, string $action): string
    {
        $code = '';
        for ($i = 0; $i < self::LONGUEUR; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        Cache::put($this->cle($user, $action), hash('sha256', $code), self::CODE_TTL);

        return $code;
    }

    /** @throws ValidationException si le code est absent, expiré ou erroné */
    public function exiger(User $user, string $action, ?string $code): void
    {
        $attendu = Cache::pull($this->cle($user, $action));
        $saisi = strtoupper(trim((string) $code));

        if (!$attendu || $saisi === '' || !hash_equals($attendu, hash('sha256', $saisi))) {
            throw ValidationException::withMessages([
                'code_confirmation' => $attendu
                    ? 'Code de confirmation incorrect. Recommencez pour obtenir un nouveau code.'
                    : 'Code de confirmation manquant ou expiré. Recommencez pour obtenir un nouveau code.',
            ]);
        }
    }

    private function cle(User $user, string $action): string
    {
        return "confirmation:{$user->id}:{$action}";
    }
}
