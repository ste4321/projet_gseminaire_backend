<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Notifications lues (cloche) : gardées sur le compte, donc identiques sur tous les navigateurs */
class NotificationsLuesTest extends TestCase
{
    use RefreshDatabase;

    private User $etudiant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->etudiant = $this->user('etudiant');
        Etudiant::create(['matricule' => '26L1001', 'nom_prenom' => 'RAKOTO Jean', 'id_user_cession' => $this->etudiant->id]);
    }

    public function test_rien_de_lu_au_depart(): void
    {
        $this->actingAs($this->etudiant, 'sanctum')
            ->getJson('/api/notifications/lues')
            ->assertOk()
            ->assertExactJson(['annonces' => null, 'notes' => null, 'edt' => null, 'cours' => null, 'lecons_vues' => []]);
    }

    public function test_ce_qui_est_lu_est_retrouve_ailleurs(): void
    {
        $this->actingAs($this->etudiant, 'sanctum')
            ->putJson('/api/notifications/lues', ['annonces' => '2026-11-05T10:00:00.000000Z', 'lecons_vues' => [3, 7]])
            ->assertOk();

        // « Autre navigateur » : même compte, nouvelle requête
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->etudiant->fresh(), 'sanctum')
            ->getJson('/api/notifications/lues')
            ->assertJsonPath('annonces', '2026-11-05T10:00:00.000000Z')
            ->assertJsonPath('notes', null)
            ->assertJsonPath('lecons_vues', [3, 7]);
    }

    public function test_l_enseignant_retrouve_ses_annonces_lues_sur_un_autre_navigateur(): void
    {
        $prof = $this->user('prof');
        $this->actingAs($prof, 'sanctum')
            ->putJson('/api/notifications/lues', ['annonces' => '2026-11-05T10:00:00.000000Z'])
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->actingAs($prof->fresh(), 'sanctum')
            ->getJson('/api/notifications/lues')
            ->assertOk()
            ->assertJsonPath('annonces', '2026-11-05T10:00:00.000000Z');

        // Chaque compte a ses propres lectures
        $this->assertNull($this->etudiant->fresh()->notifications_lues);
    }

    public function test_une_date_plus_ancienne_ne_fait_pas_reapparaitre_les_notifications(): void
    {
        $this->actingAs($this->etudiant, 'sanctum');
        $this->putJson('/api/notifications/lues', ['notes' => '2026-11-05T10:00:00.000000Z', 'lecons_vues' => [3]]);

        // Onglet resté ouvert sur un autre appareil, en retard
        $this->putJson('/api/notifications/lues', ['notes' => '2026-11-01T08:00:00.000000Z', 'lecons_vues' => [9]])
            ->assertOk()
            ->assertJsonPath('notes', '2026-11-05T10:00:00.000000Z')
            ->assertJsonPath('lecons_vues', [9, 3]);
    }

    public function test_valeurs_invalides_refusees(): void
    {
        $this->actingAs($this->etudiant, 'sanctum')
            ->putJson('/api/notifications/lues', ['notes' => 'pas une date', 'lecons_vues' => ['x']])
            ->assertUnprocessable();
    }

    public function test_la_consultation_par_l_admin_ne_marque_rien_comme_lu(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $jeton = $this->postJson("/api/consultation/{$this->etudiant->id}")->assertOk()->json('access_token');
        $this->app['auth']->forgetGuards();

        $this->withToken($jeton)->getJson('/api/notifications/lues')->assertOk();
        $this->withToken($jeton)->putJson('/api/notifications/lues', ['annonces' => '2026-11-05T10:00:00.000000Z'])
            ->assertForbidden()
            ->assertJsonPath('error_type', 'consultation_lecture_seule');

        $this->assertNull($this->etudiant->fresh()->notifications_lues);
    }
}
