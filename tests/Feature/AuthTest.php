<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_connexion_reussie_renvoie_jeton_et_profil(): void
    {
        $this->admin();

        $this->postJson('/api/login', ['email' => 'ADMIN@test.local', 'password' => 'motdepasse-admin'])
            ->assertOk()
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonPath('user.nom_prenom', 'Admin Test')
            ->assertJsonMissingPath('user.password')
            ->assertJsonStructure(['access_token', 'expires_at']);
    }

    public function test_mauvais_mot_de_passe_refuse(): void
    {
        $this->admin();

        $this->postJson('/api/login', ['email' => 'admin@test.local', 'password' => 'mauvais-mdp'])
            ->assertStatus(401);
    }

    public function test_compte_non_active_ne_peut_pas_se_connecter(): void
    {
        $user = $this->user('prof', verified: false);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'motdepasse'])
            ->assertStatus(403);
    }

    public function test_deconnexion_revoque_le_jeton(): void
    {
        $this->admin();
        $token = $this->postJson('/api/login', ['email' => 'admin@test.local', 'password' => 'motdepasse-admin'])
            ->json('access_token');

        $this->withToken($token)->getJson('/api/me')->assertOk();
        $this->withToken($token)->postJson('/api/logout')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertStatus(401);
    }

    public function test_routes_protegees_sans_jeton(): void
    {
        foreach (['/api/etudiants', '/api/user-cessions', '/api/annonces', '/api/notes/moyenne/1'] as $url) {
            $this->getJson($url)->assertStatus(401);
        }
        $this->patchJson('/api/user-cessions/bulk-toggle')->assertStatus(401);
    }

    public function test_reponse_json_meme_sans_entete_accept(): void
    {
        // Avant : erreur 500 « Route [login] not defined »
        $this->get('/api/etudiants')->assertStatus(401)->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_routes_publiques_du_site_vitrine(): void
    {
        $this->getJson('/api/infos')->assertOk();
    }

    public function test_etudiant_et_prof_n_ont_pas_acces_a_l_administration(): void
    {
        foreach (['etudiant', 'prof'] as $role) {
            $this->actingAs($this->user($role), 'sanctum');

            $this->getJson('/api/etudiants')->assertStatus(403);
            $this->getJson('/api/user-cessions')->assertStatus(403);
            $this->postJson('/api/annonces', [])->assertStatus(403);
            $this->getJson('/api/annonces')->assertOk();
            $this->getJson('/api/emplois')->assertOk();
        }
    }

    public function test_compte_desactive_avec_jeton_valide_est_bloque(): void
    {
        $this->actingAs($this->user('etudiant', verified: false), 'sanctum');

        $this->getJson('/api/annonces')->assertStatus(403);
    }
}
