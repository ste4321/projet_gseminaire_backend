<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\EtudiantParcours;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotesReferentielTest extends TestCase
{
    use RefreshDatabase;

    private EtudiantParcours $parcours;
    private Matiere $matiere;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        [$annee, $niveaux] = $this->referentiel();
        $this->admin = $this->admin();
        $this->actingAs($this->admin, 'sanctum');

        $semestre = Semestre::create(['semestre' => 'Semestre 1']);
        $this->matiere = $this->matiereAuProgramme([
            'matiere' => 'Français', 'heures' => 30, 'id_niveau' => $niveaux['L1']->id,
            'code_matiere' => 'FRS', 'coefficient' => '2', 'id_semestre' => $semestre->id,
        ]);
        $etudiant = Etudiant::create(['matricule' => 'N001', 'nom_prenom' => 'Test']);
        $this->parcours = EtudiantParcours::create([
            'id_etudiant' => $etudiant->id, 'id_annee_aca' => $annee->id, 'id_niveau' => $niveaux['L1']->id,
            'statut' => 'inscrit', 'date_inscription' => now(), 'matricule' => 'N001',
        ]);
    }

    public function test_note_zero_est_conservee(): void
    {
        $this->postJson('/api/notes/update-bulk', [
            'etudiant_parcours_id' => $this->parcours->id,
            'notes' => [['id_matiere' => $this->matiere->id, 'note' => '0']],
        ])->assertOk();

        // Avant : empty("0") supprimait la note
        $this->assertSame(0.0, (float) Note::where('id_matiere', $this->matiere->id)->value('note'));
        $this->assertSame(1, Note::count());
    }

    public function test_note_vide_supprime_la_note(): void
    {
        Note::create(['id_etudiant_parcours' => $this->parcours->id, 'id_matiere' => $this->matiere->id, 'note' => 12]);

        $this->postJson('/api/notes/update-bulk', [
            'etudiant_parcours_id' => $this->parcours->id,
            'notes' => [['id_matiere' => $this->matiere->id, 'note' => '']],
        ])->assertOk();

        $this->assertSame(0, Note::count());
    }

    public function test_note_hors_bareme_refusee(): void
    {
        $this->postJson('/api/notes/update-bulk', [
            'etudiant_parcours_id' => $this->parcours->id,
            'notes' => [['id_matiere' => $this->matiere->id, 'note' => '21']],
        ])->assertStatus(422);
    }

    public function test_notes_detaillees_d_un_etudiant(): void
    {
        Note::create(['id_etudiant_parcours' => $this->parcours->id, 'id_matiere' => $this->matiere->id, 'note' => 14]);

        // Avant : erreur 500 (relation « anneeAcademique » inexistante)
        $this->getJson('/api/notes/etudiant/' . $this->parcours->id_etudiant)
            ->assertOk()
            ->assertJsonPath('0.etudiant_parcours.annee_academique.annee_aca', '2025-2026');
    }

    public function test_relation_notes_d_une_matiere(): void
    {
        Note::create(['id_etudiant_parcours' => $this->parcours->id, 'id_matiere' => $this->matiere->id, 'note' => 10]);

        $this->assertSame(1, $this->matiere->notes()->count());
    }

    public function test_suppressions_bloquees_si_donnees_rattachees(): void
    {
        Note::create(['id_etudiant_parcours' => $this->parcours->id, 'id_matiere' => $this->matiere->id, 'note' => 10]);

        $this->deleteJson('/api/niveaux/' . $this->matiere->id_niveau)->assertStatus(409);
        $this->deleteJson('/api/semestres/' . $this->matiere->id_semestre)->assertStatus(409);
        $this->deleteJson('/api/matieres/' . $this->matiere->id)->assertStatus(409);

        $this->assertSame(1, Note::count());
    }

    public function test_code_matiere_facultatif_mais_unique(): void
    {
        $base = ['matiere' => 'Latin', 'heures' => 20, 'id_niveau' => $this->matiere->id_niveau, 'coefficient' => 1];

        // Sans code (absent ou vide) : acceptée
        $this->postJson('/api/matieres', $base)->assertCreated()->assertJsonPath('code_matiere', null);
        $this->postJson('/api/matieres', $base + ['code_matiere' => ''])->assertCreated();

        // Format libre
        $this->postJson('/api/matieres', $base + ['code_matiere' => 'LAT-1 b'])->assertCreated();

        // Même code dans le même niveau et le même semestre : refusé (l'import des notes serait ambigu)
        $this->postJson('/api/matieres', $base + ['code_matiere' => 'FRS', 'id_semestre' => $this->matiere->id_semestre])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code_matiere');

        // Même code dans un autre niveau : accepté (le fichier d'import précise le niveau)
        $autreNiveau = \App\Models\Niveau::create(['niveau' => 'L2']);
        $this->postJson('/api/matieres', ['code_matiere' => 'FRS', 'id_niveau' => $autreNiveau->id, 'id_semestre' => $this->matiere->id_semestre] + $base)
            ->assertCreated();
    }

    public function test_admin_ne_peut_pas_se_desactiver(): void
    {
        $this->patchJson("/api/user-cessions/{$this->admin->id}/toggle")->assertStatus(422);

        $this->patchJson('/api/user-cessions/bulk-toggle', ['user_ids' => [$this->admin->id], 'is_verified' => false])->assertOk();
        $this->assertTrue($this->admin->fresh()->is_verified);
    }

    public function test_statistiques_des_comptes(): void
    {
        $this->user('prof', verified: false);

        $this->getJson('/api/user-cessions/stats')
            ->assertOk()
            ->assertJsonPath('admin.actifs', 1)
            ->assertJsonPath('prof.inactifs', 1);
    }

    public function test_expediteur_d_annonce_fixe_par_le_serveur(): void
    {
        $this->postJson('/api/annonces', [
            'titre' => 'Réunion', 'description' => 'Lundi 8 h', 'expediteur' => 'Le Recteur (falsifié)',
            'id_annee_aca' => $this->parcours->id_annee_aca, 'id_niveau' => $this->parcours->id_niveau,
        ])->assertCreated()->assertJsonPath('expediteur', 'Administration');
    }
}
