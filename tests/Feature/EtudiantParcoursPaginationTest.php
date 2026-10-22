<?php

namespace Tests\Feature;

use App\Models\AnneeAca;
use App\Models\Etudiant;
use App\Models\EtudiantParcours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EtudiantParcoursPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function inscrire(string $matricule, string $nom, $annee, $niveau): void
    {
        $etudiant = Etudiant::create(['matricule' => $matricule, 'nom_prenom' => $nom, 'diocese' => 'Antsirabe']);
        EtudiantParcours::create([
            'id_etudiant' => $etudiant->id, 'id_annee_aca' => $annee->id, 'id_niveau' => $niveau->id,
            'statut' => 'inscrit', 'date_inscription' => now(), 'matricule' => $matricule,
        ]);
    }

    public function test_filtre_par_annee_et_niveau_puis_pagine(): void
    {
        [$annee, $niveaux] = $this->referentiel();
        $autreAnnee = AnneeAca::create(['annee_aca' => '2024-2025']);
        $this->actingAs($this->admin(), 'sanctum');

        foreach (range(1, 12) as $i) {
            $this->inscrire("L1-$i", "Étudiant $i", $annee, $niveaux['L1']);
        }
        $this->inscrire('L2-1', 'Autre niveau', $annee, $niveaux['L2']);
        $this->inscrire('OLD-1', 'Autre année', $autreAnnee, $niveaux['L1']);

        $this->getJson("/api/etudiant_parcours?annee={$annee->id}&niveau=L1&page=2&per_page=10")
            ->assertOk()
            ->assertJsonPath('total', 12)
            ->assertJsonPath('last_page', 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.etudiant.matricule', 'L1-2') // du plus récent au plus ancien : page 2 = les deux premiers inscrits
            ->assertJsonPath('data.0.niveau.niveau', 'L1')
            ->assertJsonPath('data.0.annee_academique.annee_aca', '2025-2026');
    }

    public function test_recherche_insensible_a_la_casse(): void
    {
        [$annee, $niveaux] = $this->referentiel();
        $this->actingAs($this->admin(), 'sanctum');

        $this->inscrire('A1', 'RAKOTO Jean', $annee, $niveaux['L1']);
        $this->inscrire('A2', 'RABE Paul', $annee, $niveaux['L1']);

        $this->getJson("/api/etudiant_parcours?annee={$annee->id}&niveau=L1&q=rakoto&page=1")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.etudiant.nom_prenom', 'RAKOTO Jean');

        // Les jokers SQL tapés par l'utilisateur sont pris au pied de la lettre
        $this->getJson("/api/etudiant_parcours?annee={$annee->id}&q=%25&page=1")
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_reserve_a_l_administration(): void
    {
        $this->actingAs($this->user('etudiant'), 'sanctum');

        $this->getJson('/api/etudiant_parcours?page=1')->assertForbidden();
    }
}
