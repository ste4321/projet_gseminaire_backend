<?php

namespace Tests\Feature;

use App\Models\Annonce;
use App\Models\Enseignant;
use App\Models\Etudiant;
use App\Models\EtudiantParcours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfilTest extends TestCase
{
    use RefreshDatabase;

    public function test_profil_etudiant_avec_compte_et_inscriptions(): void
    {
        [$annee, $niveaux] = $this->referentiel();
        $compte = $this->user('etudiant');
        $etudiant = Etudiant::create(['matricule' => '26L1001', 'nom_prenom' => 'RAKOTO Jean', 'diocese' => 'Antsirabe', 'id_user_cession' => $compte->id]);
        EtudiantParcours::create(['id_etudiant' => $etudiant->id, 'id_annee_aca' => $annee->id, 'id_niveau' => $niveaux['L1']->id,
            'statut' => 'inscrit', 'date_inscription' => now(), 'matricule' => '26L1001']);

        $this->actingAs($this->admin(), 'sanctum');
        $profil = $this->getJson("/api/etudiants/{$etudiant->id}/profil")->assertOk()->json();

        $this->assertSame('26L1001', $profil['etudiant']['matricule']);
        $this->assertSame($compte->email, $profil['compte']['email']);
        $this->assertArrayNotHasKey('password', $profil['compte']);
        $this->assertSame('L1', $profil['parcours'][0]['niveau']);
        $this->assertSame('2025-2026', $profil['parcours'][0]['annee']);
    }

    public function test_profil_enseignant_avec_ses_annonces(): void
    {
        [$annee, $niveaux] = $this->referentiel();
        $compte = $this->user('prof');
        $prof = Enseignant::create(['nom_prenom' => 'Père Jean', 'email' => $compte->email, 'id_user_cession' => $compte->id]);
        Annonce::create(['titre' => 'Devoir de latin', 'description' => '…', 'expediteur' => 'Père Jean', 'id_annee_aca' => $annee->id, 'id_niveau' => $niveaux['L2']->id]);
        Annonce::create(['titre' => 'Autre', 'description' => '…', 'expediteur' => 'Administration', 'id_annee_aca' => $annee->id, 'id_niveau' => null]);

        $this->actingAs($this->admin(), 'sanctum');
        $this->getJson("/api/enseignants/{$prof->id}/profil")
            ->assertOk()
            ->assertJsonPath('enseignant.nom_prenom', 'Père Jean')
            ->assertJsonPath('compte.is_verified', true)
            ->assertJsonCount(1, 'annonces')
            ->assertJsonPath('annonces.0.niveau', 'L2');
    }

    public function test_profil_sans_compte_et_inexistant(): void
    {
        $etudiant = Etudiant::create(['matricule' => 'X1', 'nom_prenom' => 'Sans compte']);
        $this->actingAs($this->admin(), 'sanctum');

        $this->getJson("/api/etudiants/{$etudiant->id}/profil")->assertOk()->assertJsonPath('compte', null);
        $this->getJson('/api/etudiants/999999/profil')->assertNotFound();
    }

    public function test_profils_reserves_a_l_admin(): void
    {
        $etudiant = Etudiant::create(['matricule' => 'X2', 'nom_prenom' => 'Autre']);

        foreach (['etudiant', 'prof'] as $role) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($this->user($role), 'sanctum');
            $this->getJson("/api/etudiants/{$etudiant->id}/profil")->assertForbidden();
        }
    }
}
