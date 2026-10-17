<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordTest extends TestCase
{
    use RefreshDatabase;

    private function compteProvisoire(): array
    {
        [$user, $motDePasse] = app(AccountService::class)->create('etudiant', 'eleve@test.local', 'E001');
        $user->update(['is_verified' => true]);

        return [$user, $motDePasse];
    }

    private function login(string $email, string $password)
    {
        return $this->postJson('/api/login', ['email' => $email, 'password' => $password]);
    }

    public function test_mot_de_passe_provisoire_bloque_l_acces_jusqu_au_changement(): void
    {
        [$user, $provisoire] = $this->compteProvisoire();

        $token = $this->login($user->email, $provisoire)
            ->assertOk()
            ->assertJsonPath('user.must_change_password', true)
            ->json('access_token');

        $this->withToken($token)->getJson('/api/annonces')
            ->assertStatus(403)
            ->assertJsonPath('error_type', 'password_change_required');
        $this->withToken($token)->getJson('/api/me')->assertOk();

        $this->withToken($token)->postJson('/api/password/change', [
            'current_password' => $provisoire,
            'password' => 'NouveauMotDePasse2026',
            'password_confirmation' => 'NouveauMotDePasse2026',
        ])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/annonces')->assertOk();
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertNotNull($user->fresh()->password_changed_at);
    }

    public function test_regles_du_nouveau_mot_de_passe(): void
    {
        [$user, $provisoire] = $this->compteProvisoire();
        $this->actingAs($user, 'sanctum');

        $cas = [
            'trop court' => ['current_password' => $provisoire, 'password' => 'Court1', 'password_confirmation' => 'Court1'],
            'sans chiffre' => ['current_password' => $provisoire, 'password' => 'SansAucunChiffre', 'password_confirmation' => 'SansAucunChiffre'],
            'confirmation différente' => ['current_password' => $provisoire, 'password' => 'MotDePasse2026a', 'password_confirmation' => 'MotDePasse2026b'],
        ];
        foreach ($cas as $nom => $data) {
            $this->postJson('/api/password/change', $data)->assertStatus(422)->assertJsonValidationErrors('password');
        }

        $this->postJson('/api/password/change', [
            'current_password' => 'mauvais', 'password' => 'MotDePasse2026a', 'password_confirmation' => 'MotDePasse2026a',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->postJson('/api/password/change', [
            'current_password' => $provisoire, 'password' => $provisoire, 'password_confirmation' => $provisoire,
        ])->assertStatus(422);
    }

    public function test_admin_reinitialise_un_mot_de_passe(): void
    {
        [$user, $ancien] = $this->compteProvisoire();
        $user->update(['must_change_password' => false]);
        $sessionOuverte = $user->createToken('session')->plainTextToken;

        $this->actingAs($this->admin(), 'sanctum');
        $nouveau = $this->postJson("/api/user-cessions/{$user->id}/reset-password")
            ->assertOk()
            ->json('mot_de_passe');

        $this->assertNotSame($ancien, $nouveau);
        $this->assertSame(12, strlen($nouveau));
        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertSame(0, $user->tokens()->count(), 'Les sessions ouvertes sont fermées');

        $this->app['auth']->forgetGuards();
        $this->withToken($sessionOuverte)->getJson('/api/me')->assertStatus(401);
        $this->login($user->email, $ancien)->assertStatus(401);
        $this->login($user->email, $nouveau)->assertOk();
    }

    public function test_reinitialisation_reservee_a_l_admin(): void
    {
        [$user] = $this->compteProvisoire();
        $this->actingAs($this->user('prof'), 'sanctum');

        $this->postJson("/api/user-cessions/{$user->id}/reset-password")->assertStatus(403);
    }

    public function test_admin_ne_reinitialise_pas_son_propre_compte(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum');

        $this->postJson("/api/user-cessions/{$admin->id}/reset-password")->assertStatus(422);
    }

    public function test_mot_de_passe_provisoire_lisible(): void
    {
        $service = app(AccountService::class);

        for ($i = 0; $i < 50; $i++) {
            $mdp = $service->provisionalPassword();
            $this->assertMatchesRegularExpression('/^[a-km-zA-HJ-NP-Z2-9]{12}$/', $mdp);
            $this->assertDoesNotMatchRegularExpression('/[0O1lI]/', $mdp);
        }
    }

    public function test_portail_indique_les_mots_de_passe_provisoires(): void
    {
        $this->compteProvisoire();
        $this->actingAs($this->admin(), 'sanctum');

        $this->getJson('/api/user-cessions?role=etudiant')
            ->assertOk()
            ->assertJsonPath('0.must_change_password', true);
    }
}
