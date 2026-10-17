<?php

namespace Tests\Feature;

use App\Models\AnneeAca;
use App\Models\Annonce;
use App\Models\Etudiant;
use App\Models\EtudiantParcours;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\PublicationNote;
use App\Models\Semestre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EspaceEtudiantTest extends TestCase
{
    use RefreshDatabase;

    private User $compte;
    private EtudiantParcours $parcours;
    private EtudiantParcours $parcoursAutre;
    private $annee;
    private $niveaux;
    private Semestre $s1;
    private Semestre $s2;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->annee, $this->niveaux] = $this->referentiel();
        $s1 = $this->s1 = Semestre::create(['semestre' => 'Semestre 1', 'code_semestre' => 'S1']);
        $s2 = $this->s2 = Semestre::create(['semestre' => 'Semestre 2', 'code_semestre' => 'S2']);

        $latin = Matiere::create(['matiere' => 'Latin', 'heures' => 20, 'id_niveau' => $this->niveaux['L1']->id, 'code_matiere' => 'LAT', 'coefficient' => '1', 'id_semestre' => $s1->id]);
        $theo = Matiere::create(['matiere' => 'Théologie', 'heures' => 40, 'id_niveau' => $this->niveaux['L1']->id, 'code_matiere' => 'THEO', 'coefficient' => '3', 'id_semestre' => $s1->id]);
        Matiere::create(['matiere' => 'Grec', 'heures' => 20, 'id_niveau' => $this->niveaux['L1']->id, 'code_matiere' => 'GREC', 'coefficient' => '2', 'id_semestre' => $s2->id]);

        $this->compte = $this->user('etudiant');
        $this->parcours = $this->inscrire('E001', $this->compte, 'L1');
        Note::create(['id_etudiant_parcours' => $this->parcours->id, 'id_matiere' => $latin->id, 'note' => 0]);
        Note::create(['id_etudiant_parcours' => $this->parcours->id, 'id_matiere' => $theo->id, 'note' => 16]);

        // Un autre étudiant, avec ses propres notes
        $this->parcoursAutre = $this->inscrire('E002', $this->user('etudiant'), 'L1');
        Note::create(['id_etudiant_parcours' => $this->parcoursAutre->id, 'id_matiere' => $latin->id, 'note' => 19]);
    }

    private function inscrire(string $matricule, User $compte, string $niveau): EtudiantParcours
    {
        $etudiant = Etudiant::create(['matricule' => $matricule, 'nom_prenom' => "Étudiant {$matricule}", 'id_user_cession' => $compte->id]);

        return EtudiantParcours::create([
            'id_etudiant' => $etudiant->id, 'id_annee_aca' => $this->annee->id, 'id_niveau' => $this->niveaux[$niveau]->id,
            'statut' => 'inscrit', 'date_inscription' => now(), 'matricule' => $matricule,
        ]);
    }

    private function publier(Semestre $semestre): void
    {
        PublicationNote::create(['id_annee_aca' => $this->annee->id, 'id_niveau' => $this->niveaux['L1']->id, 'id_semestre' => $semestre->id]);
    }

    public function test_releve_avec_moyenne_ponderee_et_note_zero(): void
    {
        $this->publier($this->s1);
        $this->publier($this->s2);
        $this->actingAs($this->compte, 'sanctum');

        $releve = $this->getJson('/api/etudiant/releve')->assertOk()->json();

        $this->assertSame('E001', $releve['etudiant']['matricule']);
        $this->assertSame('L1', $releve['niveau']);
        $this->assertCount(2, $releve['semestres']);
        // Semestre 1 : (0×1 + 16×3) / (1+3) = 12 ; le 0 compte ; toutes les notes saisies → Validé
        $this->assertEquals(12.0, $releve['semestres'][0]['moyenne']);
        $this->assertSame('Validé', $releve['semestres'][0]['statut']);
        $this->assertEquals(0.0, $releve['semestres'][0]['matieres'][0]['note']);
        // Semestre 2 : aucune note
        $this->assertNull($releve['semestres'][1]['moyenne']);
        $this->assertNull($releve['semestres'][1]['matieres'][0]['note']);
        // Général : 2 notes sur 3 matières, moyenne 12 ; pas de statut tant qu'il manque une note
        $this->assertEquals(12.0, $releve['general']['moyenne']);
        $this->assertNull($releve['general']['statut']);
        $this->assertSame(3, $releve['general']['matieres_total']);
        $this->assertSame(2, $releve['general']['notes_saisies']);
    }

    public function test_impossible_de_voir_le_releve_d_un_autre_etudiant(): void
    {
        $this->actingAs($this->compte, 'sanctum');

        // Même en passant l'inscription d'un autre étudiant, on n'obtient pas ses notes
        $this->getJson('/api/etudiant/releve?parcours=' . $this->parcoursAutre->id)->assertNotFound();
        $this->getJson('/api/notes/releve/' . $this->parcoursAutre->id)->assertForbidden();
        $this->getJson('/api/notes/etudiant-parcours/' . $this->parcoursAutre->id)->assertForbidden();
    }

    public function test_liste_de_ses_inscriptions(): void
    {
        $this->actingAs($this->compte, 'sanctum');

        $this->getJson('/api/etudiant/parcours')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.annee', '2025-2026')
            ->assertJsonPath('0.niveau', 'L1');
    }

    public function test_compte_etudiant_sans_dossier(): void
    {
        $this->actingAs($this->user('etudiant'), 'sanctum');

        $this->getJson('/api/etudiant/releve')->assertNotFound();
    }

    public function test_espace_etudiant_reserve_aux_etudiants(): void
    {
        $this->actingAs($this->user('prof'), 'sanctum');

        $this->getJson('/api/etudiant/releve')->assertForbidden();
    }

    public function test_admin_obtient_le_meme_releve(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        // L'administration voit toutes les notes, publiées ou non
        $this->getJson('/api/notes/releve/' . $this->parcours->id)
            ->assertOk()
            ->assertJsonPath('general.moyenne', 12)
            ->assertJsonPath('semestres.0.publie', false);
    }

    public function test_notes_invisibles_tant_que_le_semestre_n_est_pas_publie(): void
    {
        $this->actingAs($this->compte, 'sanctum');

        $releve = $this->getJson('/api/etudiant/releve')->assertOk()->json();
        $this->assertFalse($releve['semestres'][0]['publie']);
        $this->assertNull($releve['semestres'][0]['matieres'][1]['note']); // le 16 ne quitte pas le serveur
        $this->assertNull($releve['semestres'][0]['moyenne']);
        $this->assertNull($releve['general']['moyenne']);

        // Publication du semestre 1 seulement
        $this->publier($this->s1);
        $releve = $this->getJson('/api/etudiant/releve')->assertOk()->json();
        $this->assertEquals(16.0, $releve['semestres'][0]['matieres'][1]['note']);
        $this->assertEquals(12.0, $releve['general']['moyenne']);
        $this->assertFalse($releve['semestres'][1]['publie']);
        $this->assertSame(1, $releve['general']['semestres_publies']);
    }

    public function test_statut_rattrapage_sous_la_moyenne(): void
    {
        Note::where('id_etudiant_parcours', $this->parcours->id)->update(['note' => 6]);
        Note::create(['id_etudiant_parcours' => $this->parcours->id, 'id_matiere' => Matiere::where('code_matiere', 'GREC')->value('id'), 'note' => 9]);
        $this->publier($this->s1);
        $this->publier($this->s2);
        $this->actingAs($this->compte, 'sanctum');

        $releve = $this->getJson('/api/etudiant/releve')->assertOk()->json();
        $this->assertSame('Rattrapage', $releve['semestres'][0]['statut']);
        $this->assertSame('Rattrapage', $releve['general']['statut']);
    }

    public function test_publier_et_retirer_un_semestre(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $cible = ['id_annee_aca' => $this->annee->id, 'id_niveau' => $this->niveaux['L1']->id, 'id_semestre' => $this->s1->id];

        $this->postJson('/api/notes/publications', $cible + ['code_confirmation' => $this->codeConfirmation('publier_notes')])->assertCreated();
        // déjà publié : pas de doublon
        $this->postJson('/api/notes/publications', $cible + ['code_confirmation' => $this->codeConfirmation('publier_notes')])->assertOk();
        $this->assertSame(1, PublicationNote::count());

        $etat = $this->getJson("/api/notes/publications?annee={$this->annee->id}&niveau={$this->niveaux['L1']->id}")->assertOk()->json();
        $this->assertCount(2, $etat);
        $this->assertTrue($etat[0]['publie']);
        $this->assertSame(2, $etat[0]['etudiants']);
        $this->assertSame(4, $etat[0]['notes_attendues']);
        $this->assertSame(3, $etat[0]['notes_saisies']);
        $this->assertFalse($etat[1]['publie']);

        $this->deleteJson('/api/notes/publications', $cible)->assertOk();
        $this->assertSame(0, PublicationNote::count());
    }

    public function test_seul_l_admin_publie(): void
    {
        $this->actingAs($this->compte, 'sanctum');
        $cible = ['id_annee_aca' => $this->annee->id, 'id_niveau' => $this->niveaux['L1']->id, 'id_semestre' => $this->s1->id];

        $this->postJson('/api/notes/publications', $cible)->assertForbidden();
        $this->assertSame(0, PublicationNote::count());
    }

    public function test_annonces_filtrees_sur_son_annee_et_son_niveau(): void
    {
        $autreAnnee = AnneeAca::create(['annee_aca' => '2024-2025']);
        $creer = fn ($titre, $niveau, $annee = null) => Annonce::create([
            'titre' => $titre, 'description' => '…', 'expediteur' => 'Administration',
            'id_annee_aca' => ($annee ?? $this->annee)->id, 'id_niveau' => $niveau ? $this->niveaux[$niveau]->id : null,
        ]);
        $creer('Pour L1', 'L1');
        $creer('Pour tous', null);
        $creer('Pour L2', 'L2');
        $creer('Ancienne année', 'L1', $autreAnnee);

        $this->actingAs($this->compte, 'sanctum');
        $titres = collect($this->getJson('/api/annonces')->assertOk()->json())->pluck('titre')->sort()->values()->all();
        $this->assertSame(['Pour L1', 'Pour tous'], $titres);

        // L'administration voit tout
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin(), 'sanctum');
        $this->assertCount(4, $this->getJson('/api/annonces')->json());
    }

    public function test_annonce_pour_tous_les_niveaux(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        $this->postJson('/api/annonces', ['titre' => 'Messe', 'description' => 'Dimanche', 'id_annee_aca' => $this->annee->id])
            ->assertCreated()
            ->assertJsonPath('id_niveau', null);
    }
}
