<?php

namespace Tests\Feature;

use App\Models\Edt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmploiDuTempsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ajout_remplacement_et_suppression_gerent_le_fichier(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin(), 'sanctum');

        $emploi = $this->postJson('/api/emplois', ['image' => UploadedFile::fake()->image('edt.jpg'), 'semestre' => 1])
            ->assertCreated()
            ->json();
        $this->assertStringStartsWith('/storage/edt/', $emploi['image_url']);
        $premier = substr($emploi['image_url'], strlen('/storage/'));
        Storage::disk('public')->assertExists($premier);

        // Remplacement : le nouveau fichier existe, l'ancien est supprimé
        $nouvelle = $this->postJson("/api/emplois/{$emploi['id']}/upload", ['image' => UploadedFile::fake()->image('edt2.png')])
            ->assertOk()
            ->json('image_url');
        Storage::disk('public')->assertExists(substr($nouvelle, strlen('/storage/')));
        Storage::disk('public')->assertMissing($premier);

        $this->deleteJson("/api/emplois/{$emploi['id']}")->assertOk();
        Storage::disk('public')->assertMissing(substr($nouvelle, strlen('/storage/')));
        $this->assertSame(0, Edt::count());
    }

    public function test_refuse_un_fichier_qui_n_est_pas_une_image(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin(), 'sanctum');

        $this->postJson('/api/emplois', ['image' => UploadedFile::fake()->create('edt.pdf', 100, 'application/pdf'), 'semestre' => 1])
            ->assertStatus(422);
    }

    public function test_etudiant_ne_peut_pas_ajouter(): void
    {
        Storage::fake('public');
        $this->actingAs($this->user('etudiant'), 'sanctum');

        $this->postJson('/api/emplois', ['image' => UploadedFile::fake()->image('edt.jpg'), 'semestre' => 1])
            ->assertForbidden();
    }
}
