<?php

namespace Tests\Feature;

use App\Models\Info;
use App\Models\Matiere;
use App\Models\PublicationNote;
use App\Models\Semestre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private array $actualite = ['auteur' => 'Secrétariat', 'titre' => 'Messe de rentrée', 'description' => 'Lundi 7 h'];

    private function cibleNotes(): array
    {
        [$annee, $niveaux] = $this->referentiel();
        $semestre = Semestre::create(['semestre' => 'Semestre 1', 'code_semestre' => 'S1']);
        $this->matiereAuProgramme(['matiere' => 'Latin', 'heures' => 20, 'id_niveau' => $niveaux['L1']->id, 'code_matiere' => 'LAT', 'coefficient' => '1', 'id_semestre' => $semestre->id]);

        return ['id_annee_aca' => $annee->id, 'id_niveau' => $niveaux['L1']->id, 'id_semestre' => $semestre->id];
    }

    public function test_publication_refusee_sans_code(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        $this->postJson('/api/infos', $this->actualite)->assertStatus(422)->assertJsonValidationErrors('code_confirmation');
        $this->postJson('/api/notes/publications', $this->cibleNotes())->assertStatus(422)->assertJsonValidationErrors('code_confirmation');

        $this->assertSame(0, Info::count());
        $this->assertSame(0, PublicationNote::count());
    }

    public function test_code_correct_accepte_meme_en_minuscules(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $code = strtolower($this->codeConfirmation('publier_actualite'));

        $this->postJson('/api/infos', $this->actualite + ['code_confirmation' => " {$code} "])->assertCreated();
    }

    public function test_code_a_usage_unique(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $code = $this->codeConfirmation('publier_actualite');

        $this->postJson('/api/infos', $this->actualite + ['code_confirmation' => $code])->assertCreated();
        $this->postJson('/api/infos', $this->actualite + ['code_confirmation' => $code])->assertStatus(422);
        $this->assertSame(1, Info::count());
    }

    public function test_un_code_errone_annule_le_code_en_cours(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $code = $this->codeConfirmation('publier_notes');
        $cible = $this->cibleNotes();

        $this->postJson('/api/notes/publications', $cible + ['code_confirmation' => 'ZZZZZZ'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code_confirmation.0', fn ($m) => str_contains($m, 'incorrect'));
        // Pas de nouvel essai possible avec l'ancien code : il faut en redemander un
        $this->postJson('/api/notes/publications', $cible + ['code_confirmation' => $code])->assertStatus(422);
        $this->assertSame(0, PublicationNote::count());
    }

    public function test_code_lie_a_l_action(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $code = $this->codeConfirmation('publier_notes');

        $this->postJson('/api/infos', $this->actualite + ['code_confirmation' => $code])->assertStatus(422);
        $this->assertSame(0, Info::count());
    }

    public function test_code_lie_a_l_utilisateur(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $code = $this->codeConfirmation('publier_actualite');

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->user('admin'), 'sanctum');
        $this->postJson('/api/infos', $this->actualite + ['code_confirmation' => $code])->assertStatus(422);
    }

    public function test_action_inconnue_et_acces_reserve_a_l_admin(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $this->postJson('/api/confirmations', ['action' => 'supprimer_tout'])->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->user('etudiant'), 'sanctum');
        $this->postJson('/api/confirmations', ['action' => 'publier_notes'])->assertForbidden();
    }
}
