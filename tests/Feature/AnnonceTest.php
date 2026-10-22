<?php

namespace Tests\Feature;

use App\Models\Annonce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnnonceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fichier_joint_remplace_puis_retire(): void
    {
        Storage::fake('public');
        [$annee] = $this->referentiel();
        $this->actingAs($this->admin(), 'sanctum');

        $id = $this->post('/api/annonces', [
            'titre' => 'Retraite', 'description' => 'Programme de la retraite', 'id_annee_aca' => $annee->id,
            'fichier' => UploadedFile::fake()->create('programme.pdf', 20, 'application/pdf'),
        ])->assertCreated()->json('id');
        $this->assertCount(1, Storage::disk('public')->files('annonces'));

        // Modifier sans fichier ni case cochée : la pièce jointe reste
        $this->post("/api/annonces/{$id}?_method=PUT", ['titre' => 'Retraite spirituelle', 'description' => 'x', 'id_annee_aca' => $annee->id])
            ->assertOk();
        $this->assertNotNull(Annonce::find($id)->fichier);

        // Retirer : plus de pièce jointe, et le fichier quitte le disque
        $this->post("/api/annonces/{$id}?_method=PUT", [
            'titre' => 'Retraite spirituelle', 'description' => 'x', 'id_annee_aca' => $annee->id, 'supprimer_fichier' => '1',
        ])->assertOk()->assertJsonPath('fichier', null);
        $this->assertCount(0, Storage::disk('public')->files('annonces'));
    }

    public function test_titre_obligatoire(): void
    {
        $this->referentiel();
        $this->actingAs($this->admin(), 'sanctum');

        $this->postJson('/api/annonces', ['description' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['titre']);
    }

    public function test_sans_annee_l_annonce_va_a_l_annee_en_cours(): void
    {
        [$enCours, $niveaux] = $this->referentiel();
        $this->actingAs($this->admin(), 'sanctum');
        $etudiant = \App\Models\Etudiant::create(['matricule' => 'A1', 'nom_prenom' => 'Inscrit']);
        \App\Models\EtudiantParcours::create([
            'id_etudiant' => $etudiant->id, 'id_annee_aca' => $enCours->id, 'id_niveau' => $niveaux['L1']->id,
            'statut' => 'inscrit', 'date_inscription' => now(), 'matricule' => 'A1',
        ]);
        // Année suivante créée à l'avance, encore sans inscrits : pas l'année en cours
        \App\Models\AnneeAca::create(['annee_aca' => '2026-2027']);

        $id = $this->postJson('/api/annonces', ['titre' => 'Rentrée', 'description' => 'x'])
            ->assertCreated()
            ->assertJsonPath('id_annee_aca', $enCours->id)
            ->json('id');

        // Modifier sans année : l'annonce garde la sienne
        $this->putJson("/api/annonces/{$id}", ['titre' => 'Rentrée', 'description' => 'y'])->assertOk();
        $this->assertSame($enCours->id, Annonce::find($id)->id_annee_aca);
    }
}
