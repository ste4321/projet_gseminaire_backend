<?php

namespace Tests\Feature;

use App\Models\AnneeAca;
use App\Models\Etudiant;
use App\Models\EtudiantParcours;
use App\Models\Semestre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferentielsTest extends TestCase
{
    use RefreshDatabase;

    private function inscrire(AnneeAca $annee, $niveau): void
    {
        $etudiant = Etudiant::create(['matricule' => 'R' . uniqid(), 'nom_prenom' => 'Inscrit']);
        EtudiantParcours::create([
            'id_etudiant' => $etudiant->id, 'id_annee_aca' => $annee->id, 'id_niveau' => $niveau->id,
            'statut' => 'inscrit', 'date_inscription' => now(), 'matricule' => $etudiant->matricule,
        ]);
    }

    public function test_annees_avec_chiffres_et_annee_en_cours(): void
    {
        [$annee, $niveaux] = $this->referentiel();
        $preparee = AnneeAca::create(['annee_aca' => '2026-2027']);
        $this->inscrire($annee, $niveaux['L1']);
        $this->actingAs($this->admin(), 'sanctum');

        $liste = $this->getJson('/api/annee_aca')->assertOk()->json();
        $this->assertSame(['2026-2027', '2025-2026'], array_column($liste, 'annee_aca'));
        $this->assertFalse($liste[0]['courante']); // préparée à l'avance, encore sans inscrits
        $this->assertTrue($liste[1]['courante']);
        $this->assertSame(1, $liste[1]['inscrits_count']);
        $this->assertArrayHasKey('matieres_count', $liste[0]);
        $this->assertSame($preparee->id, $liste[0]['id']);
    }

    public function test_renommer_une_annee(): void
    {
        $annee = AnneeAca::create(['annee_aca' => '2026-2027']);
        AnneeAca::create(['annee_aca' => '2025-2026']);
        $this->actingAs($this->admin(), 'sanctum');

        $this->putJson("/api/annee_aca/{$annee->id}", ['annee_aca' => '2027-2028'])->assertOk();
        $this->assertSame('2027-2028', $annee->refresh()->annee_aca);
        $this->putJson("/api/annee_aca/{$annee->id}", ['annee_aca' => '2025-2026'])->assertUnprocessable();
        $this->putJson("/api/annee_aca/{$annee->id}", ['annee_aca' => '2027-2029'])->assertUnprocessable();
    }

    public function test_supprimer_une_annee_seulement_sans_donnees(): void
    {
        [$annee, $niveaux] = $this->referentiel();
        $vide = AnneeAca::create(['annee_aca' => '2026-2027']);
        $this->inscrire($annee, $niveaux['L1']);
        $this->actingAs($this->admin(), 'sanctum');

        $this->deleteJson("/api/annee_aca/{$annee->id}")->assertStatus(409)
            ->assertJsonPath('message', 'Impossible de supprimer 2025-2026 : 1 inscription(s) y sont rattachées.');
        $this->assertNotNull(AnneeAca::find($annee->id));

        $this->deleteJson("/api/annee_aca/{$vide->id}")->assertOk();
        $this->assertNull(AnneeAca::find($vide->id));
    }

    public function test_niveaux_et_semestres_avec_compteurs(): void
    {
        [$annee, $niveaux] = $this->referentiel();
        $this->inscrire($annee, $niveaux['L2']);
        Semestre::create(['semestre' => 'Semestre 1', 'code_semestre' => 'S1']);
        $this->actingAs($this->admin(), 'sanctum');

        $l2 = collect($this->getJson('/api/niveaux')->assertOk()->json())->firstWhere('niveau', 'L2');
        $this->assertSame(1, $l2['inscrits_count']);
        $this->assertSame(0, $l2['matieres_count']);
        $this->assertSame(0, $this->getJson('/api/semestres')->assertOk()->json('0.matieres_count'));
    }

    public function test_gestion_des_annees_reservee_a_l_administration(): void
    {
        $annee = AnneeAca::create(['annee_aca' => '2026-2027']);
        $this->actingAs($this->user('prof'), 'sanctum');
        $this->deleteJson("/api/annee_aca/{$annee->id}")->assertForbidden();
        $this->putJson("/api/annee_aca/{$annee->id}", ['annee_aca' => '2027-2028'])->assertForbidden();
    }
}
