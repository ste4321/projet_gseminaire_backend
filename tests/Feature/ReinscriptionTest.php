<?php

namespace Tests\Feature;

use App\Models\AnneeAca;
use App\Models\Etudiant;
use App\Models\EtudiantParcours;
use App\Models\Note;
use App\Models\Semestre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Assistant de réinscription (passage à l'année suivante) et départs des étudiants.
 */
class ReinscriptionTest extends TestCase
{
    use RefreshDatabase;

    private AnneeAca $source;
    private AnneeAca $cible;
    private $niveaux;
    private array $matieres = [];

    protected function setUp(): void
    {
        parent::setUp();
        [$this->source, $this->niveaux] = $this->referentiel(); // 2025-2026, L1 à L3
        $this->cible = AnneeAca::create(['annee_aca' => '2026-2027']);
        $semestre = Semestre::create(['semestre' => 'Semestre 1', 'code_semestre' => 'S1']);
        foreach (['L1', 'L3'] as $n) {
            $this->matieres[$n] = $this->matiereAuProgramme([
                'matiere' => "Latin {$n}", 'heures' => 20, 'id_niveau' => $this->niveaux[$n]->id,
                'code_matiere' => "LAT{$n}", 'coefficient' => '1', 'id_semestre' => $semestre->id,
            ]);
        }
    }

    /** Étudiant inscrit en 2025-2026, avec son compte et (si précisée) sa note */
    private function etudiant(string $nom, string $niveau, ?float $note): array
    {
        $user = $this->user('etudiant');
        $etudiant = Etudiant::create(['matricule' => strtoupper($nom), 'nom_prenom' => $nom, 'id_user_cession' => $user->id]);
        $parcours = EtudiantParcours::create([
            'id_etudiant' => $etudiant->id, 'id_annee_aca' => $this->source->id, 'id_niveau' => $this->niveaux[$niveau]->id,
            'statut' => 'inscrit', 'date_inscription' => now(), 'matricule' => $etudiant->matricule,
        ]);
        if ($note !== null) {
            Note::create(['id_etudiant_parcours' => $parcours->id, 'id_matiere' => $this->matieres[$niveau]->id, 'note' => $note]);
        }

        return [$etudiant, $parcours, $user];
    }

    public function test_propositions_tirees_des_resultats(): void
    {
        $this->etudiant('Admis', 'L1', 12);
        $this->etudiant('Rattrapage', 'L1', 8);
        $this->etudiant('SansNote', 'L1', null);
        $this->etudiant('Finaliste', 'L3', 15);
        [$parti] = $this->etudiant('Parti', 'L1', 14);
        $parti->update(['date_sortie' => '2026-01-15']);
        $this->actingAs($this->admin(), 'sanctum');

        $propositions = collect($this->getJson("/api/reinscriptions?source={$this->source->id}&cible={$this->cible->id}")
            ->assertOk()->json('propositions'))->keyBy('etudiant.nom_prenom');

        $this->assertFalse($propositions->has('Parti')); // déjà sorti : rien à décider
        $this->assertSame(['passage', $this->niveaux['L2']->id, false], [$propositions['Admis']['decision'], $propositions['Admis']['niveau_cible'], $propositions['Admis']['a_verifier']]);
        $this->assertSame(['redoublement', $this->niveaux['L1']->id], [$propositions['Rattrapage']['decision'], $propositions['Rattrapage']['niveau_cible']]);
        $this->assertTrue($propositions['SansNote']['a_verifier']);
        $this->assertSame('diplome', $propositions['Finaliste']['decision']);
    }

    public function test_application_des_decisions(): void
    {
        [$admis, $pAdmis, $compteAdmis] = $this->etudiant('Admis', 'L1', 12);
        [, $pRattrapage] = $this->etudiant('Rattrapage', 'L1', 8);
        [$exclu, $pExclu, $compteExclu] = $this->etudiant('Exclu', 'L1', null);
        [$diplome, $pDiplome, $compteDiplome] = $this->etudiant('Finaliste', 'L3', 15);
        $compteExclu->createToken('session');
        $this->actingAs($this->admin(), 'sanctum');

        $decisions = [
            // Niveaux incohérents envoyés exprès : le serveur impose L1 → L2 (passage) et L1 → L1 (redoublement)
            ['parcours_id' => $pAdmis->id, 'decision' => 'passage', 'niveau_cible' => $this->niveaux['L3']->id],
            ['parcours_id' => $pRattrapage->id, 'decision' => 'redoublement', 'niveau_cible' => $this->niveaux['L2']->id],
            ['parcours_id' => $pExclu->id, 'decision' => 'exclu', 'motif' => 'Conseil de discipline'],
            ['parcours_id' => $pDiplome->id, 'decision' => 'diplome'],
        ];

        // Sans code de confirmation : refusé
        $this->postJson('/api/reinscriptions', ['source' => $this->source->id, 'cible' => $this->cible->id, 'decisions' => $decisions])
            ->assertStatus(422)->assertJsonValidationErrors('code_confirmation');

        $this->postJson('/api/reinscriptions', [
            'source' => $this->source->id, 'cible' => $this->cible->id, 'decisions' => $decisions,
            'code_confirmation' => $this->codeConfirmation('reinscrire'),
        ])->assertOk()->assertJsonPath('bilan.passage', 1)->assertJsonPath('bilan.redoublement', 1);

        // Passage : inscrit en L2, même compte ; l'année précédente garde son issue
        $this->assertDatabaseHas('etudiant_parcours', ['id_etudiant' => $admis->id, 'id_annee_aca' => $this->cible->id, 'id_niveau' => $this->niveaux['L2']->id, 'statut' => 'inscrit']);
        $this->assertSame('admis', $pAdmis->fresh()->statut);
        $this->assertSame($compteAdmis->id, $admis->fresh()->id_user_cession);
        // Redoublement : de nouveau en L1
        $this->assertSame('redoublant', $pRattrapage->fresh()->statut);
        $this->assertDatabaseHas('etudiant_parcours', ['id_etudiant' => $pRattrapage->id_etudiant, 'id_annee_aca' => $this->cible->id, 'id_niveau' => $this->niveaux['L1']->id]);
        // Exclu : dossier conservé, compte désactivé, sessions fermées
        $this->assertSame('exclu', $pExclu->fresh()->statut);
        $this->assertSame('Conseil de discipline', $exclu->fresh()->motif_sortie);
        $this->assertFalse($compteExclu->fresh()->is_verified);
        $this->assertSame(0, $compteExclu->tokens()->count());
        $this->assertDatabaseMissing('etudiant_parcours', ['id_etudiant' => $exclu->id, 'id_annee_aca' => $this->cible->id]);
        // Diplômé : sorti, mais garde l'accès à ses bulletins
        $this->assertNotNull($diplome->fresh()->date_sortie);
        $this->assertSame('diplome', $pDiplome->fresh()->statut);
        $this->assertTrue($compteDiplome->fresh()->is_verified);

        // Rejouée : personne n'est inscrit deux fois
        $this->postJson('/api/reinscriptions', [
            'source' => $this->source->id, 'cible' => $this->cible->id, 'decisions' => [$decisions[0]],
            'code_confirmation' => $this->codeConfirmation('reinscrire'),
        ])->assertOk()->assertJsonPath('bilan.deja_inscrits', 1);
        $this->assertSame(1, EtudiantParcours::where('id_etudiant', $admis->id)->where('id_annee_aca', $this->cible->id)->count());
    }

    public function test_l_annee_d_arrivee_doit_suivre_l_annee_de_depart(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        $this->getJson("/api/reinscriptions?source={$this->cible->id}&cible={$this->source->id}")->assertStatus(422);
        $this->getJson("/api/reinscriptions?source={$this->source->id}&cible={$this->source->id}")->assertStatus(422);

        // Pas de saut d'année : 2025-2026 → 2027-2028 refusé, à la lecture comme à l'enregistrement
        $plusTard = AnneeAca::create(['annee_aca' => '2027-2028']);
        $this->getJson("/api/reinscriptions?source={$this->source->id}&cible={$plusTard->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', "Les étudiants de 2025-2026 ne peuvent être réinscrits qu'en 2026-2027, l'année qui suit.");
        $this->postJson('/api/reinscriptions', [
            'source' => $this->source->id, 'cible' => $plusTard->id,
            'decisions' => [['parcours_id' => 1, 'decision' => 'passage']],
        ])->assertStatus(422);
        $this->getJson("/api/reinscriptions?source={$this->source->id}&cible={$this->cible->id}")->assertOk();
    }

    public function test_sortie_en_cours_d_annee_puis_retour(): void
    {
        [$etudiant, $parcours, $compte] = $this->etudiant('Pierre', 'L1', null);
        $compte->createToken('session');
        $this->actingAs($this->admin(), 'sanctum');

        $this->postJson("/api/etudiants/{$etudiant->id}/sortie", ['statut' => 'abandon', 'date' => '2026-03-01', 'motif' => 'Raisons familiales'])
            ->assertOk()->assertJsonPath('etudiant.date_sortie', '2026-03-01');
        $this->assertSame('abandon', $parcours->fresh()->statut);
        $this->assertFalse($compte->fresh()->is_verified);
        $this->assertSame(0, $compte->tokens()->count());

        // Date future refusée
        $this->postJson("/api/etudiants/{$etudiant->id}/sortie", ['statut' => 'abandon', 'date' => now()->addDay()->toDateString()])
            ->assertStatus(422);

        $this->deleteJson("/api/etudiants/{$etudiant->id}/sortie")->assertOk();
        $this->assertNull($etudiant->fresh()->date_sortie);
        $this->assertSame('inscrit', $parcours->fresh()->statut);
        $this->assertTrue($compte->fresh()->is_verified);
    }

    public function test_desactiver_un_compte_ferme_ses_sessions(): void
    {
        $compte = $this->user('etudiant');
        $compte->createToken('session');
        $this->actingAs($this->admin(), 'sanctum');

        $this->patchJson("/api/user-cessions/{$compte->id}/toggle")->assertOk()->assertJsonPath('is_verified', false);
        $this->assertSame(0, User::find($compte->id)->tokens()->count());
    }
}
