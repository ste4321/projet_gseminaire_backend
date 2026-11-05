<?php

namespace Tests\Feature;

use App\Events\LeconMiseAJour;
use App\Models\AnneeAca;
use App\Models\Enseignant;
use App\Models\Etudiant;
use App\Models\EtudiantParcours;
use App\Models\Lecon;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\ProgrammeMatiere;
use App\Models\PublicationNote;
use App\Models\Semestre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EspaceEnseignantTest extends TestCase
{
    use RefreshDatabase;

    private AnneeAca $annee;
    private $niveaux;
    private Semestre $semestre;
    private Matiere $matiere;
    private Matiere $autreMatiere;
    private User $compte;
    private Enseignant $enseignant;
    private EtudiantParcours $parcours;
    private EtudiantParcours $parti;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->annee, $this->niveaux] = $this->referentiel();
        $this->semestre = Semestre::create(['semestre' => 'Semestre 1', 'code_semestre' => 'S1']);
        $commun = ['id_niveau' => $this->niveaux['L1']->id, 'id_semestre' => $this->semestre->id, 'coefficient' => 2, 'heures' => 30];
        $this->matiere = $this->matiereAuProgramme($commun + ['matiere' => 'Théologie', 'code_matiere' => 'THG']);
        $this->autreMatiere = $this->matiereAuProgramme($commun + ['matiere' => 'Philosophie', 'code_matiere' => 'PHI']);

        $this->compte = $this->user('prof');
        $this->enseignant = Enseignant::create(['nom_prenom' => 'Père Jean', 'id_user_cession' => $this->compte->id]);
        $this->attribuer($this->matiere, $this->enseignant);

        $this->parcours = $this->inscrire('E1', 'Séminariste A');
        $this->parti = $this->inscrire('E2', 'Séminariste B', EtudiantParcours::ABANDON);

        $this->actingAs($this->compte, 'sanctum');
    }

    private function attribuer(Matiere $matiere, Enseignant $enseignant): void
    {
        ProgrammeMatiere::where('id_matiere', $matiere->id)->update(['id_enseignant' => $enseignant->id]);
    }

    private function inscrire(string $matricule, string $nom, string $statut = EtudiantParcours::INSCRIT): EtudiantParcours
    {
        $etudiant = Etudiant::create(['matricule' => $matricule, 'nom_prenom' => $nom]);

        return EtudiantParcours::create([
            'id_etudiant' => $etudiant->id, 'id_annee_aca' => $this->annee->id, 'id_niveau' => $this->niveaux['L1']->id,
            'statut' => $statut, 'date_inscription' => now(), 'matricule' => $matricule,
        ]);
    }

    private function saisir(Matiere $matiere, $note, ?int $parcours = null)
    {
        return $this->putJson("/api/enseignant/matieres/{$matiere->id}/notes", [
            'annee' => $this->annee->id,
            'notes' => [['id_etudiant_parcours' => $parcours ?? $this->parcours->id, 'note' => $note]],
        ]);
    }

    public function test_voit_seulement_ses_matieres_avec_l_avancement(): void
    {
        Note::create(['id_etudiant_parcours' => $this->parcours->id, 'id_matiere' => $this->matiere->id, 'note' => 12]);

        $this->getJson('/api/enseignant/matieres')
            ->assertOk()
            ->assertJsonPath('annee', $this->annee->id)
            ->assertJsonCount(1, 'matieres')
            ->assertJsonPath('matieres.0.matiere', 'Théologie')
            ->assertJsonPath('matieres.0.etudiants', 1)
            ->assertJsonPath('matieres.0.notes_saisies', 1)
            ->assertJsonPath('matieres.0.publie', false);
    }

    public function test_sans_matiere_attribuee_la_liste_est_vide(): void
    {
        ProgrammeMatiere::query()->update(['id_enseignant' => null]);

        $this->getJson('/api/enseignant/matieres')->assertOk()->assertJsonPath('matieres', []);
    }

    public function test_saisit_modifie_et_efface_une_note_y_compris_zero(): void
    {
        $this->saisir($this->matiere, 14.5)->assertOk();
        $cle = ['id_etudiant_parcours' => $this->parcours->id, 'id_matiere' => $this->matiere->id];
        $this->assertEquals(14.5, Note::where($cle)->value('note'));

        $this->saisir($this->matiere, 0)->assertOk();
        $this->assertEquals(0, Note::where($cle)->value('note'));
        $this->assertSame(1, Note::where($cle)->count());

        $this->saisir($this->matiere, null)->assertOk();
        $this->assertSame(0, Note::where($cle)->count());
    }

    public function test_note_hors_bareme_refusee(): void
    {
        $this->saisir($this->matiere, 21)->assertUnprocessable();
        $this->assertSame(0, Note::count());
    }

    public function test_matiere_d_un_autre_enseignant_refusee(): void
    {
        $this->getJson("/api/enseignant/matieres/{$this->autreMatiere->id}/notes?annee={$this->annee->id}")->assertForbidden();
        $this->saisir($this->autreMatiere, 10)->assertForbidden();
        $this->assertSame(0, Note::count());
    }

    public function test_etudiant_parti_ou_d_un_autre_niveau_refuse(): void
    {
        $this->saisir($this->matiere, 10, $this->parti->id)->assertUnprocessable();
        $this->assertSame(0, Note::count());
    }

    public function test_les_notes_sont_verrouillees_apres_publication(): void
    {
        PublicationNote::create([
            'id_annee_aca' => $this->annee->id, 'id_niveau' => $this->niveaux['L1']->id, 'id_semestre' => $this->semestre->id,
        ]);

        $this->getJson("/api/enseignant/matieres/{$this->matiere->id}/notes?annee={$this->annee->id}")
            ->assertOk()->assertJsonPath('verrouillee', true);
        $this->getJson('/api/enseignant/matieres')->assertJsonPath('matieres.0.publie', true);
        $this->saisir($this->matiere, 10)->assertStatus(409);
        $this->assertSame(0, Note::count());
    }

    public function test_liste_de_notes_sans_les_etudiants_partis(): void
    {
        $this->getJson("/api/enseignant/matieres/{$this->matiere->id}/notes?annee={$this->annee->id}")
            ->assertOk()
            ->assertJsonCount(1, 'etudiants')
            ->assertJsonPath('etudiants.0.nom_prenom', 'Séminariste A')
            ->assertJsonPath('etudiants.0.note', null);
    }

    public function test_les_notes_renvoyees_sont_des_nombres(): void
    {
        $this->saisir($this->matiere, 12.5)->assertOk();

        $note = $this->getJson("/api/enseignant/matieres/{$this->matiere->id}/notes?annee={$this->annee->id}")->json('etudiants.0.note');
        $this->assertSame(12.5, $note);
    }

    public function test_le_releve_compte_une_note_saisie_par_l_enseignant(): void
    {
        $this->saisir($this->matiere, 15)->assertOk();

        $this->actingAs($this->admin(), 'sanctum');
        $this->getJson("/api/notes/releve/{$this->parcours->id}")
            ->assertOk()
            ->assertJsonPath('general.notes_saisies', 1);
    }

    public function test_etudiant_et_admin_n_ont_pas_acces_a_l_espace(): void
    {
        $this->actingAs($this->user('etudiant'), 'sanctum');
        $this->getJson('/api/enseignant/matieres')->assertForbidden();

        $this->actingAs($this->admin(), 'sanctum');
        $this->getJson('/api/enseignant/matieres')->assertForbidden();
    }

    public function test_cycle_de_vie_d_une_lecon_avec_fichier(): void
    {
        Storage::fake('public');

        $id = $this->post('/api/enseignant/lecons', [
            'id_annee_aca' => $this->annee->id, 'id_matiere' => $this->matiere->id, 'titre' => 'Introduction',
            'fichier' => UploadedFile::fake()->create('cours.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('publie_le', null)->json('id');

        $chemin = str_replace('/storage/', '', Lecon::find($id)->fichier);
        Storage::disk('public')->assertExists($chemin);
        $this->assertSame('cours.pdf', Lecon::find($id)->nom_fichier);

        $this->post("/api/enseignant/lecons/{$id}", ['titre' => 'Introduction', 'publier' => '1'], ['Accept' => 'application/json'])->assertOk();
        $publieLe = Lecon::find($id)->publie_le;
        $this->assertNotNull($publieLe);

        $this->post("/api/enseignant/lecons/{$id}", ['titre' => 'Sans rien', 'publier' => '1', 'supprimer_fichier' => '1'], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        Storage::disk('public')->assertExists($chemin);

        $this->deleteJson("/api/enseignant/lecons/{$id}")->assertOk();
        $this->assertNull(Lecon::find($id));
        Storage::disk('public')->assertMissing($chemin);
    }

    public function test_envoi_trop_volumineux_explique_en_francais(): void
    {
        // PHP vide une requête plus grosse que post_max_size : Laravel la refuse avant le contrôleur
        $this->call('POST', '/api/enseignant/lecons', [], [], [], [
            'CONTENT_LENGTH' => 1024 * 1024 * 1024, 'HTTP_ACCEPT' => 'application/json',
        ])
            ->assertStatus(413)
            ->assertJsonPath('message', fn ($m) => str_starts_with($m, 'Le fichier est trop volumineux'))
            ->assertJsonStructure(['errors' => ['fichier']]);
        $this->assertSame(0, Lecon::count());
    }

    public function test_lecon_vide_ou_sur_la_matiere_d_un_autre_refusee(): void
    {
        $donnees = ['id_annee_aca' => $this->annee->id, 'id_matiere' => $this->matiere->id, 'titre' => 'Vide'];

        $this->postJson('/api/enseignant/lecons', $donnees)->assertUnprocessable();
        $this->postJson('/api/enseignant/lecons', ['id_matiere' => $this->autreMatiere->id, 'contenu' => 'Texte'] + $donnees)->assertForbidden();
        $this->assertSame(0, Lecon::count());
    }

    public function test_le_nouvel_enseignant_reprend_les_lecons_et_l_ancien_les_perd(): void
    {
        $lecon = Lecon::create(['id_annee_aca' => $this->annee->id, 'id_matiere' => $this->matiere->id, 'id_enseignant' => $this->enseignant->id, 'titre' => 'L1', 'contenu' => 'x']);
        $autre = $this->user('prof');
        $this->attribuer($this->matiere, Enseignant::create(['nom_prenom' => 'Père Luc', 'id_user_cession' => $autre->id]));

        $this->deleteJson("/api/enseignant/lecons/{$lecon->id}")->assertForbidden();

        $this->actingAs($autre, 'sanctum');
        $this->getJson("/api/enseignant/lecons?annee={$this->annee->id}")->assertOk()->assertJsonCount(1);
    }

    public function test_l_etudiant_ne_voit_que_les_lecons_publiees_de_son_niveau(): void
    {
        $commun = ['id_annee_aca' => $this->annee->id, 'id_matiere' => $this->matiere->id, 'id_enseignant' => $this->enseignant->id, 'contenu' => 'x'];
        Lecon::create($commun + ['titre' => 'Publiée', 'publie_le' => now()]);
        Lecon::create($commun + ['titre' => 'Brouillon']);
        $autreNiveau = $this->matiereAuProgramme(['matiere' => 'Exégèse', 'id_niveau' => $this->niveaux['L2']->id, 'coefficient' => 1, 'heures' => 10]);
        Lecon::create(['id_matiere' => $autreNiveau->id, 'titre' => 'Autre niveau', 'publie_le' => now()] + $commun);

        $compte = $this->user('etudiant');
        $this->parcours->etudiant->update(['id_user_cession' => $compte->id]);
        $this->actingAs($compte, 'sanctum');

        $this->getJson('/api/etudiant/lecons')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.titre', 'Publiée')
            ->assertJsonPath('0.matiere', 'Théologie')
            ->assertJsonPath('0.enseignant', 'Père Jean');
    }

    public function test_les_etudiants_du_niveau_sont_prevenus_de_la_publication_d_une_lecon(): void
    {
        Event::fake([LeconMiseAJour::class]);
        $compte = $this->user('etudiant');
        $this->parcours->etudiant->update(['id_user_cession' => $compte->id]);
        $donnees = ['id_annee_aca' => $this->annee->id, 'id_matiere' => $this->matiere->id, 'contenu' => 'Texte'];

        // Brouillon : personne n'est dérangé
        $id = $this->postJson('/api/enseignant/lecons', $donnees + ['titre' => 'Brouillon'])->assertCreated()->json('id');
        Event::assertNotDispatched(LeconMiseAJour::class);

        // Première publication, puis modification, retrait et suppression d'une leçon publiée
        $this->postJson("/api/enseignant/lecons/{$id}", ['titre' => 'Introduction', 'contenu' => 'Texte', 'publier' => true])->assertOk();
        $this->postJson("/api/enseignant/lecons/{$id}", ['titre' => 'Introduction (v2)', 'contenu' => 'Texte', 'publier' => true])->assertOk();
        $this->postJson("/api/enseignant/lecons/{$id}", ['titre' => 'Introduction (v2)', 'contenu' => 'Texte'])->assertOk();
        $publiee = $this->postJson('/api/enseignant/lecons', $donnees + ['titre' => 'Chapitre 1', 'publier' => true])->json('id');
        $this->deleteJson("/api/enseignant/lecons/{$publiee}")->assertOk();

        $actions = [];
        Event::assertDispatched(LeconMiseAJour::class, function (LeconMiseAJour $e) use ($compte, &$actions) {
            $actions[] = $e->action;
            return $e->idsUtilisateurs === [$compte->id];
        });
        $this->assertSame(['publiee', 'modifiee', 'retiree', 'publiee', 'retiree'], $actions);
        Event::assertDispatched(LeconMiseAJour::class, fn ($e) => $e->broadcastWith()['matiere'] === 'Théologie'
            && $e->broadcastWith()['enseignant'] === 'Père Jean' && $e->broadcastAs() === 'lecons.maj');
    }

    public function test_l_etudiant_ouvre_le_detail_d_une_lecon_de_son_niveau_seulement(): void
    {
        $commun = ['id_annee_aca' => $this->annee->id, 'id_matiere' => $this->matiere->id, 'id_enseignant' => $this->enseignant->id, 'contenu' => 'Texte complet'];
        $lecon = Lecon::create($commun + ['titre' => 'Introduction', 'publie_le' => now()->subDay()]);
        $suivante = Lecon::create($commun + ['titre' => 'Chapitre 1', 'publie_le' => now()]);
        $brouillon = Lecon::create($commun + ['titre' => 'Brouillon']);
        $autreNiveau = $this->matiereAuProgramme(['matiere' => 'Exégèse', 'id_niveau' => $this->niveaux['L2']->id, 'coefficient' => 1, 'heures' => 10]);
        $ailleurs = Lecon::create(['id_matiere' => $autreNiveau->id, 'titre' => 'Autre niveau', 'publie_le' => now()] + $commun);

        $compte = $this->user('etudiant');
        $this->parcours->etudiant->update(['id_user_cession' => $compte->id]);
        $this->actingAs($compte, 'sanctum');

        $this->getJson("/api/etudiant/lecons/{$lecon->id}")
            ->assertOk()
            ->assertJsonPath('titre', 'Introduction')
            ->assertJsonPath('contenu', 'Texte complet')
            ->assertJsonPath('matiere', 'Théologie')
            ->assertJsonPath('enseignant', 'Père Jean')
            ->assertJsonPath('memes_matiere.0.id', $suivante->id)
            ->assertJsonCount(2, 'memes_matiere');

        $this->getJson("/api/etudiant/lecons/{$brouillon->id}")->assertNotFound();
        $this->getJson("/api/etudiant/lecons/{$ailleurs->id}")->assertNotFound();
        $this->getJson('/api/etudiant/lecons/999999')->assertNotFound();
    }

    public function test_l_admin_attribue_une_matiere_pour_une_annee_seulement(): void
    {
        $autreAnnee = AnneeAca::create(['annee_aca' => '2026-2027']);
        ProgrammeMatiere::query()->update(['id_enseignant' => null]);
        $this->actingAs($this->admin(), 'sanctum');

        $this->putJson("/api/matieres/{$this->matiere->id}", [
            'matiere' => 'Théologie', 'code_matiere' => 'THG', 'id_niveau' => $this->niveaux['L1']->id, 'id_semestre' => $this->semestre->id,
            'coefficient' => 2, 'heures' => 30, 'id_annee_aca' => $this->annee->id, 'id_enseignant' => $this->enseignant->id,
        ])->assertOk()->assertJsonPath('enseignant', 'Père Jean');

        $ligne = fn ($annee) => ProgrammeMatiere::where('id_matiere', $this->matiere->id)->where('id_annee_aca', $annee)->value('id_enseignant');
        $this->assertSame($this->enseignant->id, $ligne($this->annee->id));
        $this->assertNull($ligne($autreAnnee->id));
    }

    public function test_une_nouvelle_annee_garde_les_attributions(): void
    {
        $nouvelle = AnneeAca::create(['annee_aca' => '2026-2027']);

        $this->assertSame(
            $this->enseignant->id,
            ProgrammeMatiere::where('id_annee_aca', $nouvelle->id)->where('id_matiere', $this->matiere->id)->value('id_enseignant')
        );
    }

    public function test_supprimer_la_fiche_libere_la_matiere_sans_perdre_les_lecons(): void
    {
        $lecon = Lecon::create(['id_annee_aca' => $this->annee->id, 'id_matiere' => $this->matiere->id, 'id_enseignant' => $this->enseignant->id, 'titre' => 'L', 'contenu' => 'x', 'publie_le' => now()]);

        $this->actingAs($this->admin(), 'sanctum');
        $this->deleteJson("/api/enseignants/{$this->enseignant->id}")->assertOk();

        $this->assertNull(ProgrammeMatiere::where('id_matiere', $this->matiere->id)->value('id_enseignant'));
        $this->assertNull($lecon->refresh()->id_enseignant);
    }

    public function test_une_matiere_avec_des_lecons_reste_au_catalogue(): void
    {
        Lecon::create(['id_annee_aca' => $this->annee->id, 'id_matiere' => $this->matiere->id, 'titre' => 'L', 'contenu' => 'x']);

        $this->actingAs($this->admin(), 'sanctum');
        $this->deleteJson("/api/matieres/{$this->matiere->id}")->assertStatus(409);
        $this->assertNotNull(Matiere::find($this->matiere->id));
    }
}
