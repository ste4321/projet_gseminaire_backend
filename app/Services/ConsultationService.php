<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * « Consulter l'espace de… » : un administrateur ouvre l'espace d'un étudiant ou d'un enseignant
 * tel que celui-ci le voit, sans connaître son mot de passe.
 *
 * Garde-fous :
 * - jeton distinct, nommé NOM_JETON, valable DUREE_MINUTES ; les sessions de la personne ne sont pas touchées ;
 * - lecture seule : toute requête d'écriture est refusée (middleware LectureSeuleConsultation) ;
 * - l'administrateur à l'origine est mémorisé dans les capacités du jeton (« par:<id> ») et journalisé.
 */
class ConsultationService
{
    public const NOM_JETON = 'consultation';
    public const DUREE_MINUTES = 60;
    public const ROLES_CONSULTABLES = ['etudiant', 'prof'];

    /** @return array{0: string, 1: \Illuminate\Support\Carbon} jeton en clair et date d'expiration */
    public function demarrer(User $admin, User $cible): array
    {
        $expire = now()->addMinutes(self::DUREE_MINUTES);
        $jeton = $cible->createToken(self::NOM_JETON, ['consultation', "par:{$admin->id}"], $expire)->plainTextToken;

        return [$jeton, $expire];
    }

    public static function estEnConsultation(Request $request): bool
    {
        $jeton = $request->user()?->currentAccessToken();

        return $jeton instanceof PersonalAccessToken && $jeton->name === self::NOM_JETON;
    }

    /** Identifiant de l'administrateur qui a ouvert la consultation */
    public static function adminOrigine(Request $request): ?int
    {
        foreach ($request->user()?->currentAccessToken()?->abilities ?? [] as $capacite) {
            if (str_starts_with($capacite, 'par:')) {
                return (int) substr($capacite, 4);
            }
        }

        return null;
    }
}
