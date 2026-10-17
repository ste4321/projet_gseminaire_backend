<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\EtudiantParcours;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ConsultationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $etudiant;

    protected function setUp(): void
    {
        parent::setUp();
        [$annee, $niveaux] = $this->referentiel();
        $this->admin = $this->admin();
        $this->etudiant = $this->user('etudiant');
        $fiche = Etudiant::create(['matricule' => '26L1001', 'nom_prenom' => 'RAKOTO Jean', 'id_user_cession' => $this->etudiant->id]);
        EtudiantParcours::create(['id_etudiant' => $fiche->id, 'id_annee_aca' => $annee->id, 'id_niveau' => $niveaux['L1']->id,
            'statut' => 'inscrit', 'date_inscription' => now(), 'matricule' => '26L1001']);
    }

    /** Ouvre la consultation en tant qu'admin et renvoie le jeton obtenu (les requêtes suivantes l'utilisent) */
    private function ouvrir(User $cible): string
    {
        $this->actingAs($this->admin, 'sanctum');
        $jeton = $this->postJson("/api/consultation/{$cible->id}")
            ->assertOk()
            ->assertJsonPath('user.role', $cible->role)
            ->assertJsonMissingPath('user.password')
            ->json('access_token');
        $this->app['auth']->forgetGuards();

        return $jeton;
    }

    public function test_l_admin_voit_l_espace_etudiant_en_lecture_seule(): void
    {
        $sessionEtudiant = $this->etudiant->createToken('auth_token')->accessToken;
        $jeton = $this->ouvrir($this->etudiant);

        $this->withToken($jeton)->getJson('/api/etudiant/releve')->assertOk()->assertJsonPath('etudiant.matricule', '26L1001');
        $this->withToken($jeton)->getJson('/api/me')->assertOk()->assertJsonPath('user.id', $this->etudiant->id);

        // Aucune écriture : ni changer le mot de passe, ni prolonger la session
        $this->withToken($jeton)->postJson('/api/password/change', ['current_password' => 'x', 'password' => 'Nouveau-mdp-2026', 'password_confirmation' => 'Nouveau-mdp-2026'])
            ->assertForbidden()
            ->assertJsonPath('error_type', 'consultation_lecture_seule');
        $this->withToken($jeton)->postJson('/api/refresh')->assertForbidden();

        // La session de l'étudiant n'est pas touchée
        $this->assertNotNull(PersonalAccessToken::find($sessionEtudiant->id));
    }

    public function test_compte_avec_mot_de_passe_provisoire_ou_non_active(): void
    {
        $this->etudiant->update(['must_change_password' => true, 'is_verified' => false]);
        $jeton = $this->ouvrir($this->etudiant);

        $this->withToken($jeton)->getJson('/api/annonces')->assertOk();
    }

    public function test_jeton_limite_a_une_heure_et_marque(): void
    {
        $this->ouvrir($this->etudiant);

        $jeton = PersonalAccessToken::where('tokenable_id', $this->etudiant->id)->where('name', 'consultation')->sole();
        $this->assertContains("par:{$this->admin->id}", $jeton->abilities);
        $this->assertTrue($jeton->expires_at->between(now()->addMinutes(59), now()->addMinutes(61)));
    }

    public function test_fin_de_consultation_supprime_le_jeton(): void
    {
        $jeton = $this->ouvrir($this->etudiant);

        $this->withToken($jeton)->postJson('/api/consultation/fin')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($jeton)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_espace_enseignant_consultable(): void
    {
        $prof = $this->user('prof');
        $jeton = $this->ouvrir($prof);

        $this->withToken($jeton)->getJson('/api/annonces')->assertOk();
    }

    public function test_impossible_de_consulter_un_admin(): void
    {
        $autreAdmin = $this->user('admin');
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson("/api/consultation/{$autreAdmin->id}")->assertStatus(422);
    }

    public function test_reserve_a_l_admin_et_pas_de_consultation_en_cascade(): void
    {
        $prof = $this->user('prof');

        $this->actingAs($this->etudiant, 'sanctum');
        $this->postJson("/api/consultation/{$prof->id}")->assertForbidden();

        // Depuis l'espace consulté, impossible d'ouvrir un autre espace
        $this->app['auth']->forgetGuards();
        $jeton = $this->ouvrir($this->etudiant);
        $this->withToken($jeton)->postJson("/api/consultation/{$prof->id}")->assertForbidden();
    }
}
