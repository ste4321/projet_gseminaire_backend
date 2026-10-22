<?php

namespace Tests\Feature;

use App\Models\AnneeAca;
use App\Models\Etudiant;
use App\Models\EtudiantParcours;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\ProgrammeMatiere;
use App\Models\Semestre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Programme par année : ajouter, retirer une matière ou changer un coefficient une année
 * ne modifie pas les relevés des autres années.
 */
class ProgrammeTest extends TestCase
{
    use RefreshDatabase;

    private AnneeAca $ancienne;
    private AnneeAca $nouvelle;
    private $niveaux;
    private Semestre $s1;
    private Matiere $latin;
    private EtudiantParcours $parcoursAncien;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->ancienne, $this->niveaux] = $this->referentiel();
        $this->actingAs($this->admin(), 'sanctum');
        $this->s1 = Semestre::create(['semestre' => 'Semestre 1', 'code_semestre' => 'S1']);

        $this->latin = $this->matiereAuProgramme([
            'matiere' => 'Latin', 'heures' => 20, 'id_niveau' => $this->niveaux['L1']->id,
            'code_matiere' => 'LAT', 'coefficient' => '2', 'id_semestre' => $this->s1->id,
        ]);
        $this->parcoursAncien = $this->inscrire('A001', $this->ancienne);
        Note::create(['id_etudiant_parcours' => $this->parcoursAncien->id, 'id_matiere' => $this->latin->id, 'note' => 12]);

        // Nouvelle année : reprend automatiquement le programme de la précédente
        $this->nouvelle = AnneeAca::create(['annee_aca' => '2026-2027']);
    }

    private function inscrire(string $matricule, AnneeAca $annee): EtudiantParcours
    {
        $etudiant = Etudiant::firstOrCreate(['matricule' => $matricule], ['nom_prenom' => "Étudiant {$matricule}"]);

        return EtudiantParcours::create([
            'id_etudiant' => $etudiant->id, 'id_annee_aca' => $annee->id, 'id_niveau' => $this->niveaux['L1']->id,
            'statut' => 'inscrit', 'date_inscription' => now(), 'matricule' => $matricule,
        ]);
    }

    private function releve(EtudiantParcours $parcours): array
    {
        return $this->getJson("/api/notes/releve/{$parcours->id}")->assertOk()->json();
    }

    public function test_nouvelle_annee_reprend_le_programme_precedent(): void
    {
        $this->assertTrue(ProgrammeMatiere::where('id_annee_aca', $this->nouvelle->id)->where('id_matiere', $this->latin->id)->exists());
    }

    public function test_matiere_ajoutee_une_annee_n_apparait_pas_dans_les_annees_passees(): void
    {
        $this->postJson('/api/matieres', [
            'matiere' => 'Grec', 'heures' => 30, 'id_niveau' => $this->niveaux['L1']->id, 'code_matiere' => 'GREC',
            'coefficient' => 3, 'id_semestre' => $this->s1->id, 'id_annee_aca' => $this->nouvelle->id,
        ])->assertCreated()->assertJsonPath('au_programme', true);

        // L'ancien relevé reste complet : une seule matière, notée, donc un résultat
        $ancien = $this->releve($this->parcoursAncien);
        $this->assertSame(1, $ancien['general']['matieres_total']);
        $this->assertSame('Validé', $ancien['general']['statut']);

        $nouveau = $this->releve($this->inscrire('A001', $this->nouvelle));
        $this->assertSame(2, $nouveau['general']['matieres_total']);
    }

    public function test_coefficient_change_une_annee_sans_toucher_aux_autres(): void
    {
        $this->putJson("/api/matieres/{$this->latin->id}", [
            'matiere' => 'Latin', 'heures' => 25, 'id_niveau' => $this->niveaux['L1']->id, 'code_matiere' => 'LAT',
            'coefficient' => 5, 'id_semestre' => $this->s1->id, 'id_annee_aca' => $this->nouvelle->id,
        ])->assertOk()->assertJsonPath('coefficient', 5);

        $this->assertEquals(2, $this->releve($this->parcoursAncien)['semestres'][0]['matieres'][0]['coefficient']);
        $this->assertEquals(5, $this->releve($this->inscrire('A001', $this->nouvelle))['semestres'][0]['matieres'][0]['coefficient']);
    }

    public function test_retirer_du_programme_conserve_les_notes_passees(): void
    {
        $this->deleteJson('/api/programme', ['id_annee_aca' => $this->nouvelle->id, 'id_matiere' => $this->latin->id])->assertOk();

        $this->assertSame(1, Note::count());
        $this->assertSame(1, $this->releve($this->parcoursAncien)['general']['matieres_total']);
        $this->assertSame(0, $this->releve($this->inscrire('A001', $this->nouvelle))['general']['matieres_total']);
    }

    public function test_retrait_avec_notes_les_conserve_et_les_retrouve_a_la_remise(): void
    {
        $this->deleteJson('/api/programme', ['id_annee_aca' => $this->ancienne->id, 'id_matiere' => $this->latin->id])
            ->assertOk()
            ->assertJsonPath('notes_conservees', 1);

        // La note reste en base mais ne compte plus dans le relevé de l'année
        $this->assertSame(1, Note::count());
        $this->assertSame(0, $this->releve($this->parcoursAncien)['general']['matieres_total']);

        // Remise au programme : la note revient telle quelle
        $this->postJson('/api/programme', ['id_annee_aca' => $this->ancienne->id, 'id_matiere' => $this->latin->id])->assertCreated();
        $this->assertEquals(12, $this->releve($this->parcoursAncien)['semestres'][0]['matieres'][0]['note']);
    }

    public function test_remettre_au_programme_et_reprendre_un_programme(): void
    {
        ProgrammeMatiere::where('id_annee_aca', $this->nouvelle->id)->delete();

        $this->postJson('/api/programme/copier', [
            'id_annee_aca' => $this->nouvelle->id, 'depuis' => $this->ancienne->id, 'id_niveau' => $this->niveaux['L1']->id,
        ])->assertOk()->assertJsonPath('ajoutees', 1);

        // Déjà au programme : rien de plus
        $this->postJson('/api/programme', ['id_annee_aca' => $this->nouvelle->id, 'id_matiere' => $this->latin->id])->assertOk();
        $this->assertSame(1, ProgrammeMatiere::where('id_annee_aca', $this->nouvelle->id)->count());
    }

    public function test_note_refusee_pour_une_matiere_hors_programme(): void
    {
        ProgrammeMatiere::where('id_annee_aca', $this->nouvelle->id)->delete();
        $parcours = $this->inscrire('A001', $this->nouvelle);

        $this->postJson('/api/notes/update-bulk', [
            'etudiant_parcours_id' => $parcours->id,
            'notes' => [['id_matiere' => $this->latin->id, 'note' => 14]],
        ])->assertStatus(422);

        $this->assertSame(0, Note::where('id_etudiant_parcours', $parcours->id)->count());
    }

    public function test_liste_des_matieres_vue_depuis_une_annee(): void
    {
        ProgrammeMatiere::where('id_annee_aca', $this->nouvelle->id)->delete();

        $this->getJson("/api/matieres?annee={$this->ancienne->id}")
            ->assertOk()
            ->assertJsonPath('0.au_programme', true)
            ->assertJsonPath('0.notes_annee', 1)
            ->assertJsonPath('0.historique.0.annee_aca', '2025-2026');

        $this->getJson("/api/matieres?annee={$this->nouvelle->id}")
            ->assertOk()
            ->assertJsonPath('0.au_programme', false)
            ->assertJsonPath('0.notes_annee', 0)
            ->assertJsonPath('0.notes_total', 1);
    }

    public function test_niveau_d_une_matiere_notee_non_modifiable(): void
    {
        $this->putJson("/api/matieres/{$this->latin->id}", [
            'matiere' => 'Latin', 'heures' => 20, 'id_niveau' => $this->niveaux['L2']->id, 'code_matiere' => 'LAT',
            'coefficient' => 2, 'id_semestre' => $this->s1->id, 'id_annee_aca' => $this->nouvelle->id,
        ])->assertStatus(409);
    }

    public function test_la_base_refuse_d_effacer_les_notes_avec_la_matiere(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->latin->delete();
    }

    public function test_creer_une_annee_reprend_le_programme_et_valide_le_format(): void
    {
        $this->postJson('/api/annee_aca', ['annee_aca' => ' 2027 - 2028 '])
            ->assertCreated()
            ->assertJsonPath('annee.annee_aca', '2027-2028')
            ->assertJsonPath('matieres_reprises', 1);

        $this->postJson('/api/annee_aca', ['annee_aca' => '2027-2028'])->assertStatus(422); // existe déjà
        $this->postJson('/api/annee_aca', ['annee_aca' => '2027/2028'])->assertStatus(422);
        $this->postJson('/api/annee_aca', ['annee_aca' => '2028-2030'])->assertStatus(422);
    }

    public function test_seul_l_admin_cree_une_annee(): void
    {
        $this->actingAs($this->user('prof'), 'sanctum');
        $this->postJson('/api/annee_aca', ['annee_aca' => '2030-2031'])->assertForbidden();
    }
}
