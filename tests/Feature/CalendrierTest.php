<?php

namespace Tests\Feature;

use App\Events\CalendrierMisAJour;
use App\Models\Calendrier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CalendrierTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_calendrier_2025_2026_est_repris_et_affiche_sur_le_site(): void
    {
        $this->getJson('/api/calendrier')
            ->assertOk()
            ->assertJsonPath('annee', '2025-2026')
            ->assertJsonPath('semestres.0.titre', 'Premier semestre')
            ->assertJsonPath('semestres.0.debut', '2025-09-25')
            ->assertJsonPath('semestres.0.evenements.7', [
                'id' => Calendrier::first()->evenements[7]->id,
                'date' => '2026-01-22', 'end' => '2026-02-04', 'join' => 'du', 'label' => 'Examens semestriels',
            ])
            ->assertJsonCount(13, 'semestres.1.evenements');
    }

    public function test_un_brouillon_n_est_pas_affiche_puis_la_publication_le_remplace(): void
    {
        Event::fake([CalendrierMisAJour::class]);
        $this->actingAs($this->admin(), 'sanctum');

        $id = $this->postJson('/api/calendriers', ['annee' => '2026-2027'])->assertCreated()->assertJsonPath('publie', false)->json('id');
        $this->postJson("/api/calendriers/{$id}/evenements", ['semestre' => 1, 'date_debut' => '2026-09-24', 'libelle' => 'Rentrée'])
            ->assertCreated();
        Event::assertNotDispatched(CalendrierMisAJour::class);
        $this->getJson('/api/calendrier')->assertJsonPath('annee', '2025-2026');

        $this->putJson("/api/calendriers/{$id}", ['publie' => true])->assertOk();
        Event::assertDispatched(CalendrierMisAJour::class);
        $this->getJson('/api/calendrier')
            ->assertJsonPath('annee', '2026-2027')
            // bornes non saisies : déduites des événements
            ->assertJsonPath('semestres.0.debut', '2026-09-24')
            ->assertJsonCount(1, 'semestres');
    }

    public function test_copie_d_une_annee_avec_dates_decalees(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $source = Calendrier::first();

        $res = $this->postJson('/api/calendriers', ['annee' => '2026-2027', 'copier_de' => $source->id])->assertCreated();
        $res->assertJsonPath('s1_debut', '2026-09-25')->assertJsonCount(22, 'evenements');
        $this->assertSame('2026-11-01', collect($res->json('evenements'))->firstWhere('libelle', 'La Toussaint (pas de cours)')['date_debut']);
    }

    public function test_validation_d_un_evenement(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $id = Calendrier::first()->id;

        $this->postJson("/api/calendriers/{$id}/evenements", ['semestre' => 3, 'date_debut' => '2026-02-10', 'date_fin' => '2026-02-01', 'libelle' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['semestre', 'date_fin', 'liaison', 'libelle']);
        $this->postJson('/api/calendriers', ['annee' => '2025-2026'])->assertUnprocessable()->assertJsonValidationErrors('annee');

        // « Deux jours » : uniquement deux jours consécutifs
        $this->postJson("/api/calendriers/{$id}/evenements", ['semestre' => 1, 'date_debut' => '2026-02-06', 'date_fin' => '2026-02-09', 'liaison' => 'et', 'libelle' => 'Symposium'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_fin');
        $this->postJson("/api/calendriers/{$id}/evenements", ['semestre' => 1, 'date_debut' => '2026-02-28', 'date_fin' => '2026-03-01', 'liaison' => 'et', 'libelle' => 'Symposium'])
            ->assertCreated();
    }

    public function test_reserve_a_l_administration(): void
    {
        $this->getJson('/api/calendriers')->assertUnauthorized();
        $this->postJson('/api/calendriers', ['annee' => '2026-2027'])->assertUnauthorized();
    }
}
