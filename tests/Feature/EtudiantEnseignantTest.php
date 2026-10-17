<?php

namespace Tests\Feature;

use App\Models\Enseignant;
use App\Models\Etudiant;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class EtudiantEnseignantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->referentiel();
        $this->actingAs($this->admin(), 'sanctum');
    }

    public function test_deux_etudiants_sans_email_peuvent_etre_crees(): void
    {
        foreach (['M001', 'M002'] as $matricule) {
            $this->postJson('/api/etudiants', ['matricule' => $matricule, 'nom_prenom' => "Étudiant {$matricule}", 'niveau' => 'L1'])
                ->assertCreated()
                ->assertJsonStructure(['mot_de_passe']);
        }

        $this->assertSame(2, User::where('email', 'like', '%@' . AccountService::PLACEHOLDER_DOMAIN)->count());
    }

    public function test_mot_de_passe_stocke_hache_et_valide(): void
    {
        $res = $this->postJson('/api/etudiants', ['matricule' => 'M010', 'nom_prenom' => 'Test', 'niveau' => 'L2', 'email' => 'm010@test.local'])
            ->assertCreated();

        $user = User::where('email', 'm010@test.local')->firstOrFail();
        $this->assertTrue(Hash::check($res->json('mot_de_passe'), $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertFalse(Schema::hasTable('passwords'), 'Plus aucun mot de passe en clair en base');
    }

    public function test_email_deja_utilise_refuse_proprement(): void
    {
        $this->postJson('/api/etudiants', ['matricule' => 'M020', 'nom_prenom' => 'A', 'niveau' => 'L1', 'email' => 'admin@test.local'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_matricule_en_double_refuse(): void
    {
        $this->postJson('/api/etudiants', ['matricule' => 'M030', 'nom_prenom' => 'A', 'niveau' => 'L1'])->assertCreated();
        $this->postJson('/api/etudiants', ['matricule' => 'M030', 'nom_prenom' => 'B', 'niveau' => 'L1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('matricule');
    }

    public function test_modification_email_synchronise_le_compte(): void
    {
        $id = $this->postJson('/api/etudiants', ['matricule' => 'M040', 'nom_prenom' => 'A', 'niveau' => 'L1'])->json('etudiant.id');

        $this->putJson("/api/etudiants/{$id}", ['matricule' => 'M040', 'nom_prenom' => 'A', 'niveau' => 'L2', 'email' => 'Nouveau@Test.local'])
            ->assertOk();

        $etudiant = Etudiant::find($id);
        $this->assertSame('nouveau@test.local', $etudiant->userCession->email);
        $this->assertSame('L2', $etudiant->parcours()->first()->niveau->niveau);
    }

    public function test_suppression_etudiant_supprime_son_compte(): void
    {
        $res = $this->postJson('/api/etudiants', ['matricule' => 'M050', 'nom_prenom' => 'A', 'niveau' => 'L1'])->assertCreated();
        $userId = $res->json('user.id');

        $this->deleteJson('/api/etudiants/' . $res->json('etudiant.id'))->assertOk();

        $this->assertNull(User::find($userId));
    }

    public function test_modification_email_enseignant_est_enregistree(): void
    {
        $id = $this->postJson('/api/enseignants', ['nom_prenom' => 'Père Jean'])->assertCreated()->json('enseignant.id');

        // Avant : le champ « mail » était lu au lieu de « email », l'email n'était jamais mis à jour
        $this->putJson("/api/enseignants/{$id}", ['nom_prenom' => 'Père Jean', 'email' => 'jean@test.local'])->assertOk();

        $enseignant = Enseignant::find($id);
        $this->assertSame('jean@test.local', $enseignant->email);
        $this->assertSame('jean@test.local', $enseignant->userCession->email);
    }

    public function test_compte_enseignant_consultable(): void
    {
        $id = $this->postJson('/api/enseignants', ['nom_prenom' => 'Père Paul'])->json('enseignant.id');

        // Avant : erreur 500 (relation « user » inexistante)
        $this->getJson("/api/enseignants/{$id}/cession")->assertOk()->assertJsonPath('cession.role', 'prof');
    }

    public function test_import_etudiants_sans_email_et_ligne_invalide(): void
    {
        $file = $this->excel('Etudiant_L1_2025-2026.xlsx', [
            ['Nom', 'Matricule', 'Diocèse', 'Email', 'Téléphone'],
            ['Alpha', 'I001', 'Antsirabe', '', ''],
            ['Beta', 'I002', 'Fianarantsoa', '', ''],
            ['Gamma', '', '', '', ''],              // matricule manquant
            ['Delta', 'I003', '', 'pas-un-email', ''],
        ]);

        $this->post('/api/etudiants/import', ['file' => $file])
            ->assertOk()
            ->assertJsonPath('total_rows', 4)
            ->assertJsonPath('processed_rows', 3)
            ->assertJsonPath('created_accounts', 3)
            ->assertJsonCount(3, 'comptes')
            ->assertJsonStructure(['comptes' => [['matricule', 'nom_prenom', 'email', 'mot_de_passe']]])
            ->assertJsonPath('error_count', 2);

        $this->assertSame(3, Etudiant::whereNotNull('id_user_cession')->count());
    }

    public function test_import_etudiants_nom_de_fichier_invalide(): void
    {
        $file = $this->excel('liste.xlsx', [['Nom', 'Matricule'], ['A', 'X1']]);

        $this->post('/api/etudiants/import', ['file' => $file])->assertStatus(422);
    }

    private function excel(string $name, array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
