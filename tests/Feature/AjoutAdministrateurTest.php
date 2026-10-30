<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AjoutAdministrateurTest extends TestCase
{
    use RefreshDatabase;

    private function donnees(array $autres = []): array
    {
        return $autres + [
            'nom_prenom' => 'Père Paul',
            'email' => 'Paul@Exemple.mg',
            'telephone' => '034 12 345 67',
            'current_password' => 'motdepasse-admin',
        ];
    }

    public function test_cree_un_administrateur_actif_avec_mot_de_passe_provisoire(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        $reponse = $this->postJson('/api/user-cessions/admins', $this->donnees())->assertCreated();

        $compte = User::where('email', 'paul@exemple.mg')->firstOrFail();
        $this->assertSame('admin', $compte->role);
        $this->assertTrue($compte->is_verified);
        $this->assertTrue($compte->must_change_password);
        $this->assertTrue(Hash::check($reponse->json('mot_de_passe'), $compte->password));
        $this->assertSame('Père Paul', Admin::where('id_user_cession', $compte->id)->value('nom_prenom'));
    }

    public function test_mot_de_passe_obligatoire(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        $this->postJson('/api/user-cessions/admins', ['nom_prenom' => 'Père Paul', 'email' => 'paul@exemple.mg'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');
        $this->assertFalse(User::where('email', 'paul@exemple.mg')->exists());
    }

    public function test_mauvais_mot_de_passe_refuse(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        $this->postJson('/api/user-cessions/admins', $this->donnees(['current_password' => 'mauvais']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');
        $this->assertFalse(User::where('email', 'paul@exemple.mg')->exists());
    }

    public function test_bloque_apres_cinq_essais_meme_avec_le_bon_mot_de_passe(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/user-cessions/admins', $this->donnees(['current_password' => 'mauvais']))->assertUnprocessable();
        }

        $this->postJson('/api/user-cessions/admins', $this->donnees())->assertStatus(429);
        $this->assertFalse(User::where('email', 'paul@exemple.mg')->exists());
    }

    public function test_email_deja_pris_refuse_sans_adresse_de_remplacement(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $existant = $this->user('prof');
        $total = User::count();

        $this->postJson('/api/user-cessions/admins', $this->donnees(['email' => strtoupper($existant->email)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
        $this->assertSame($total, User::count());
    }

    public function test_reserve_a_l_administration(): void
    {
        $this->actingAs($this->user('prof'), 'sanctum');

        $this->postJson('/api/user-cessions/admins', $this->donnees())->assertForbidden();
        $this->assertFalse(User::where('email', 'paul@exemple.mg')->exists());
    }
}
