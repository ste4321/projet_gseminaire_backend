<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\EtudiantParcours;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class NoteImportTest extends TestCase
{
    use RefreshDatabase;

    private Matiere $matiere;
    private EtudiantParcours $parcours;

    protected function setUp(): void
    {
        parent::setUp();
        [$annee, $niveaux] = $this->referentiel();
        $s1 = Semestre::create(['semestre' => 'Semestre 1', 'code_semestre' => 'S1']);
        Semestre::create(['semestre' => 'Semestre 2', 'code_semestre' => 'S2']);

        $this->matiere = Matiere::create([
            'matiere' => 'Français', 'heures' => 30, 'id_niveau' => $niveaux['L1']->id,
            'code_matiere' => 'FRS', 'coefficient' => 2, 'id_semestre' => $s1->id,
        ]);
        $etudiant = Etudiant::create(['matricule' => 'A1', 'nom_prenom' => 'RAKOTO Jean']);
        $this->parcours = EtudiantParcours::create([
            'id_etudiant' => $etudiant->id, 'id_annee_aca' => $annee->id, 'id_niveau' => $niveaux['L1']->id,
            'statut' => 'inscrit', 'date_inscription' => now(), 'matricule' => 'A1',
        ]);

        $this->actingAs($this->admin(), 'sanctum');
    }

    /** Fichier Excel réel (colonnes matricule, note) portant le nom donné */
    private function fichier(string $nom, array $lignes): UploadedFile
    {
        $classeur = new Spreadsheet();
        $classeur->getActiveSheet()->fromArray([['matricule', 'note'], ...$lignes], null, 'A1', true); // un 0 est une note
        $chemin = tempnam(sys_get_temp_dir(), 'notes') . '.xlsx';
        (new Xlsx($classeur))->save($chemin);

        return new UploadedFile($chemin, $nom, null, null, true);
    }

    private function importer(string $nom, array $lignes = [['A1', 14.5]])
    {
        return $this->post('/api/notes/import', ['file' => $this->fichier($nom, $lignes)], ['Accept' => 'application/json']);
    }

    public function test_import_avec_le_semestre_de_la_matiere(): void
    {
        $this->importer('NOTES_FRS_L1_S1_2025-2026.xlsx', [['A1', '14,5'], ['INCONNU', 12], ['A1', 25]])
            ->assertOk()
            ->assertJsonPath('imported', 1)
            ->assertJsonCount(2, 'errors');

        $this->assertEquals(14.5, Note::where('id_etudiant_parcours', $this->parcours->id)->value('note'));
    }

    public function test_note_zero_importee(): void
    {
        $this->importer('NOTES_FRS_L1_S1_2025-2026.xlsx', [['A1', 0]])
            ->assertOk()
            ->assertJsonPath('imported', 1)
            ->assertJsonPath('errors', []);

        $this->assertSame(0.0, (float) Note::where('id_etudiant_parcours', $this->parcours->id)->value('note'));
    }

    public function test_semestre_different_de_celui_de_la_matiere_refuse(): void
    {
        $this->importer('NOTES_FRS_L1_S2_2025-2026.xlsx')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Aucune matière FRS en L1 · S2. Ce code existe en : L1 · S1.');

        $this->assertSame(0, Note::count());
    }

    public function test_meme_code_a_deux_niveaux_le_fichier_choisit_la_bonne_matiere(): void
    {
        $l2 = \App\Models\Niveau::where('niveau', 'L2')->first();
        $frsL2 = Matiere::create([
            'matiere' => 'Français avancé', 'heures' => 30, 'id_niveau' => $l2->id,
            'code_matiere' => 'FRS', 'coefficient' => 2, 'id_semestre' => $this->matiere->id_semestre,
        ]);

        $this->importer('NOTES_FRS_L1_S1_2025-2026.xlsx', [['A1', 11]])->assertOk()->assertJsonPath('imported', 1);

        $this->assertSame($this->matiere->id, Note::first()->id_matiere);
        $this->assertSame(0, Note::where('id_matiere', $frsL2->id)->count());
    }

    public function test_ancien_format_sans_semestre_refuse_avec_explication(): void
    {
        $this->importer('NOTES_FRS_L1_2025-2026.xlsx')
            ->assertStatus(422)
            ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains($m, 'semestre (S1, S2…) est désormais obligatoire'))->etc());
    }

    public function test_semestre_inconnu_signale(): void
    {
        $this->importer('NOTES_FRS_L1_S9_2025-2026.xlsx')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Introuvable dans la base : semestre S9.');
    }
}
