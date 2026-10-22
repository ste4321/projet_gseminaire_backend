<?php

namespace Tests;

use App\Models\Admin;
use App\Models\AnneeAca;
use App\Models\Matiere;
use App\Models\ProgrammeMatiere;
use App\Models\Niveau;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function admin(): User
    {
        $user = User::create([
            'email' => 'admin@test.local',
            'password' => Hash::make('motdepasse-admin'),
            'role' => 'admin',
            'is_verified' => true,
        ]);
        Admin::create(['id_user_cession' => $user->id, 'nom_prenom' => 'Admin Test', 'email' => $user->email]);

        return $user;
    }

    protected function user(string $role, bool $verified = true): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'email' => "{$role}{$n}@test.local",
            'password' => Hash::make('motdepasse'),
            'role' => $role,
            'is_verified' => $verified,
        ]);
    }

    /** Demande un code de confirmation (publier_notes, publier_actualite) pour l'utilisateur connecté */
    protected function codeConfirmation(string $action): string
    {
        return $this->postJson('/api/confirmations', ['action' => $action])->assertCreated()->json('code');
    }

    /** Année académique + niveaux L1 à L3 */
    protected function referentiel(): array
    {
        $annee = AnneeAca::create(['annee_aca' => '2025-2026']);
        $niveaux = collect(['L1', 'L2', 'L3'])->mapWithKeys(fn ($n) => [$n => Niveau::create(['niveau' => $n])]);

        return [$annee, $niveaux];
    }

    /** Matière du catalogue, mise au programme des années existantes (avec son coefficient et ses heures) */
    protected function matiereAuProgramme(array $attributs): Matiere
    {
        $matiere = Matiere::create($attributs);
        foreach (AnneeAca::pluck('id') as $annee) {
            ProgrammeMatiere::create([
                'id_annee_aca' => $annee, 'id_matiere' => $matiere->id,
                'coefficient' => $matiere->coefficient, 'heures' => $matiere->heures,
            ]);
        }

        return $matiere;
    }
}
