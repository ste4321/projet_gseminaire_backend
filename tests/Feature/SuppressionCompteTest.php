<?php

namespace Tests\Feature;

use App\Models\Enseignant;
use App\Models\Etudiant;
use App\Models\EtudiantParcours;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuppressionCompteTest extends TestCase
{
    use RefreshDatabase;

    public function test_supprimer_le_compte_d_un_etudiant_exclu_conserve_son_dossier(): void
    {
        [$annee, $niveaux] = $this->referentiel();
        $compte = $this->user('etudiant');
        $compte->createToken('auth_token');
        $etudiant = Etudiant::create(['matricule' => 'EX1', 'nom_prenom' => 'Séminariste 2', 'id_user_cession' => $compte->id]);
        $parcours = EtudiantParcours::create([
            'id_etudiant' => $etudiant->id, 'id_annee_aca' => $annee->id, 'id_niveau' => $niveaux['L1']->id,
            'statut' => 'exclu', 'date_inscription' => now(), 'matricule' => $etudiant->matricule,
        ]);

        $this->actingAs($this->admin(), 'sanctum');
        $this->deleteJson("/api/user-cessions/{$compte->id}", ['code_confirmation' => $this->codeConfirmation('supprimer_compte')])
            ->assertOk()
            ->assertJsonPath('message', 'Compte de Séminariste 2 supprimé. Son dossier (notes, bulletins, parcours) est conservé.');

        $this->assertNull(User::find($compte->id));
        $this->assertSame(0, $compte->tokens()->count());
        $this->assertNull($etudiant->refresh()->id_user_cession);
        $this->assertNotNull(EtudiantParcours::find($parcours->id));
    }

    public function test_supprimer_le_compte_d_un_enseignant_conserve_sa_fiche_sans_parler_de_bulletins(): void
    {
        $compte = $this->user('prof');
        $enseignant = Enseignant::create(['nom_prenom' => 'Père Jean', 'id_user_cession' => $compte->id]);

        $this->actingAs($this->admin(), 'sanctum');
        $this->deleteJson("/api/user-cessions/{$compte->id}", ['code_confirmation' => $this->codeConfirmation('supprimer_compte')])
            ->assertOk()
            ->assertJsonPath('message', 'Compte de Père Jean supprimé. Sa fiche enseignant est conservée.');

        $this->assertNull(User::find($compte->id));
        $this->assertNull($enseignant->refresh()->id_user_cession);
    }

    public function test_suppression_groupee_de_comptes_enseignants(): void
    {
        $a = $this->user('prof');
        $b = $this->user('prof');
        $this->actingAs($this->admin(), 'sanctum');

        $this->postJson('/api/user-cessions/bulk-delete', ['user_ids' => [$a->id, $b->id], 'code_confirmation' => $this->codeConfirmation('supprimer_compte')])
            ->assertOk()
            ->assertJsonPath('message', '2 comptes supprimés. Les fiches enseignant sont conservées.');
    }

    public function test_code_de_confirmation_exige(): void
    {
        $compte = $this->user('etudiant');
        $this->actingAs($this->admin(), 'sanctum');

        $this->deleteJson("/api/user-cessions/{$compte->id}")->assertUnprocessable()->assertJsonValidationErrors('code_confirmation');
        $this->assertNotNull(User::find($compte->id));
    }

    public function test_ni_son_propre_compte_ni_le_dernier_administrateur(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum');
        $this->deleteJson("/api/user-cessions/{$admin->id}", ['code_confirmation' => $this->codeConfirmation('supprimer_compte')])
            ->assertUnprocessable();

        // Un second administrateur, seul autre actif que soi : on peut le supprimer tant qu'il en reste un actif (soi)
        $autre = $this->user('admin');
        $this->deleteJson("/api/user-cessions/{$autre->id}", ['code_confirmation' => $this->codeConfirmation('supprimer_compte')])
            ->assertOk();
    }

    public function test_suppression_groupee_avec_un_seul_code(): void
    {
        $a = $this->user('etudiant');
        $b = $this->user('etudiant');
        $etudiant = Etudiant::create(['matricule' => 'EX2', 'nom_prenom' => 'Séminariste 3', 'id_user_cession' => $b->id]);
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum');

        $this->postJson('/api/user-cessions/bulk-delete', ['user_ids' => [$a->id, $b->id], 'code_confirmation' => $this->codeConfirmation('supprimer_compte')])
            ->assertOk()
            ->assertJsonPath('message', '2 comptes supprimés. Les dossiers (notes, bulletins, parcours) sont conservés.');
        $this->assertSame(0, User::whereIn('id', [$a->id, $b->id])->count());
        $this->assertNull($etudiant->refresh()->id_user_cession);

        // Sa propre ligne dans la sélection : rien n'est supprimé
        $c = $this->user('etudiant');
        $this->postJson('/api/user-cessions/bulk-delete', ['user_ids' => [$c->id, $admin->id], 'code_confirmation' => $this->codeConfirmation('supprimer_compte')])
            ->assertUnprocessable();
        $this->assertNotNull(User::find($c->id));
    }

    public function test_reserve_a_l_administration(): void
    {
        $compte = $this->user('etudiant');
        $this->actingAs($this->user('prof'), 'sanctum');
        $this->deleteJson("/api/user-cessions/{$compte->id}")->assertForbidden();
    }
}
