<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Page « Comptes et accès » : liste des comptes par rôle */
class ComptesTest extends TestCase
{
    use RefreshDatabase;

    public function test_liste_par_role_et_role_inconnu_refuse(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $this->user('prof');
        $this->user('etudiant', verified: false);

        $this->getJson('/api/user-cessions?role=etudiant')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.role', 'etudiant')
            ->assertJsonPath('0.is_verified', false);

        $this->getJson('/api/user-cessions?role=superadmin')->assertStatus(422);
    }

    public function test_activation_groupee_avec_message_lisible(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $a = $this->user('etudiant', verified: false);
        $b = $this->user('etudiant', verified: false);

        $this->patchJson('/api/user-cessions/bulk-toggle', ['user_ids' => [$a->id, $b->id], 'is_verified' => true])
            ->assertOk()
            ->assertJsonPath('updated_count', 2)
            ->assertJsonPath('message', '2 comptes activés.');
        $this->assertTrue($a->fresh()->is_verified);

        $this->patchJson('/api/user-cessions/bulk-toggle', ['user_ids' => [], 'is_verified' => true])->assertStatus(422);
    }
}
