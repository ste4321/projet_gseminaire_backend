<?php

namespace Tests\Feature;

use App\Models\AnneeAca;
use App\Models\Enseignant;
use App\Models\Etudiant;
use App\Models\EtudiantParcours;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\ProgrammeMatiere;
use App\Models\PublicationNote;
use App\Models\Semestre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Import d'un fichier de notes par l'enseignant : seulement ses propres matières, avant publication */
class ImportNotesEnseignantTest extends TestCase
{
    use RefreshDatabase;

    private AnneeAca $annee;
    private $niveaux;
    private Semestre $semestre;
    private Matiere $matiere;
    private Matiere $autreMatiere;
    private EtudiantParcours $parcours;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->annee, $this->niveaux] = $this->referentiel();
        $this->semestre = Semestre::create(['semestre' => 'Semestre 1', 'code_semestre' => 'S1']);
        $commun = ['id_niveau' => $this->niveaux['L1']->id, 'id_semestre' => $this->semestre->id, 'coefficient' => 2, 'heures' => 30];
        $this->matiere = $this->matiereAuProgramme($commun + ['matiere' => 'Patrologie', 'code_matiere' => 'PATR']);
        $this->autreMatiere = $this->matiereAuProgramme($commun + ['matiere' => 'Philosophie', 'code_matiere' => 'PHI']);

        $compte = $this->user('prof');
        $moi = Enseignant::create(['nom_prenom' => 'Père Paul', 'id_user_cession' => $compte->id]);
        $collegue = Enseignant::create(['nom_prenom' => 'Père Pierre', 'id_user_cession' => $this->user('prof')->id]);
        ProgrammeMatiere::where('id_matiere', $this->matiere->id)->update(['id_enseignant' => $moi->id]);
        ProgrammeMatiere::where('id_matiere', $this->autreMatiere->id)->update(['id_enseignant' => $collegue->id]);

        $this->parcours = $this->inscrire('E1', 'Séminariste A');
        $this->inscrire('E2', 'Séminariste B', EtudiantParcours::ABANDON);

        $this->actingAs($compte, 'sanctum');
    }

    private function inscrire(string $matricule, string $nom, string $statut = EtudiantParcours::INSCRIT): EtudiantParcours
    {
        $etudiant = Etudiant::create(['matricule' => $matricule, 'nom_prenom' => $nom]);

        return EtudiantParcours::create([
            'id_etudiant' => $etudiant->id, 'id_annee_aca' => $this->annee->id, 'id_niveau' => $this->niveaux['L1']->id,
            'statut' => $statut, 'date_inscription' => now(), 'matricule' => $matricule,
        ]);
    }

    private function importer(string $nom, array $lignes = [['E1', 14.5]])
    {
        $classeur = new Spreadsheet();
        $classeur->getActiveSheet()->fromArray([['matricule', 'note'], ...$lignes], null, 'A1', true);
        $chemin = tempnam(sys_get_temp_dir(), 'notes') . '.xlsx';
        (new Xlsx($classeur))->save($chemin);

        return $this->post('/api/enseignant/notes/import', ['file' => new UploadedFile($chemin, $nom, null, null, true)], ['Accept' => 'application/json']);
    }

    public function test_importe_les_notes_de_sa_matiere(): void
    {
        $this->importer('NOTES_PATR_L1_S1_2025-2026.xlsx', [['E1', '14,5'], ['E2', 12], ['INCONNU', 10], ['E1', 25]])
            ->assertOk()
            ->assertJsonPath('imported', 1)
            ->assertJsonPath('matiere', $this->matiere->id)
            ->assertJsonPath('annee', $this->annee->id)
            ->assertJsonCount(3, 'errors'); // étudiant parti, inconnu, note hors barème

        $this->assertEquals(14.5, Note::where('id_etudiant_parcours', $this->parcours->id)->value('note'));
        $this->assertSame(1, Note::count());
    }

    public function test_matiere_d_un_autre_enseignant_refusee(): void
    {
        $this->importer('NOTES_PHI_L1_S1_2025-2026.xlsx')
            ->assertForbidden()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'ne vous est pas attribuée'));
        $this->assertSame(0, Note::count());
    }

    public function test_semestre_publie_refuse(): void
    {
        PublicationNote::create([
            'id_annee_aca' => $this->annee->id, 'id_niveau' => $this->niveaux['L1']->id, 'id_semestre' => $this->semestre->id,
        ]);

        $this->importer('NOTES_PATR_L1_S1_2025-2026.xlsx')->assertStatus(409);
        $this->assertSame(0, Note::count());
    }

    public function test_nom_de_fichier_invalide_refuse(): void
    {
        $this->importer('mes_notes.xlsx')->assertUnprocessable();
        $this->assertSame(0, Note::count());
    }

    public function test_l_admin_et_l_etudiant_n_ont_pas_acces(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $this->importer('NOTES_PATR_L1_S1_2025-2026.xlsx')->assertForbidden();
        $this->assertSame(0, Note::count());
    }
}
