<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\EtudiantParcours;
use App\Models\Matiere;
use App\Models\Note;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_tableau_de_bord_vide_ne_plante_pas(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        $this->getJson('/api/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('annee', null)
            ->assertJsonPath('etudiants.inscrits', 0)
            ->assertJsonPath('configuration.niveaux', 0);
    }

    public function test_statistiques_reelles_de_l_annee(): void
    {
        [$annee, $niveaux] = $this->referentiel();
        $this->actingAs($this->admin(), 'sanctum');
        $this->user('prof', verified: false);

        $matiere = Matiere::create([
            'matiere' => 'Latin', 'heures' => 20, 'id_niveau' => $niveaux['L1']->id,
            'code_matiere' => 'LAT', 'coefficient' => '1',
        ]);

        foreach ([['A1', 'Antsirabe', 'L1'], ['A2', 'Antsirabe', 'L1'], ['A3', '', 'L2']] as [$matricule, $diocese, $niveau]) {
            $etudiant = Etudiant::create(['matricule' => $matricule, 'nom_prenom' => $matricule, 'diocese' => $diocese]);
            $parcours = EtudiantParcours::create([
                'id_etudiant' => $etudiant->id, 'id_annee_aca' => $annee->id, 'id_niveau' => $niveaux[$niveau]->id,
                'statut' => 'inscrit', 'date_inscription' => now(), 'matricule' => $matricule,
            ]);
            if ($matricule === 'A1') {
                Note::create(['id_etudiant_parcours' => $parcours->id, 'id_matiere' => $matiere->id, 'note' => 0]);
            }
        }

        $this->getJson('/api/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('annee.annee_aca', '2025-2026')
            ->assertJsonPath('etudiants.inscrits', 3)
            ->assertJsonPath('etudiants.par_niveau.0', ['niveau' => 'L1', 'total' => 2])
            ->assertJsonPath('etudiants.par_diocese.0', ['diocese' => 'Antsirabe', 'total' => 2])
            ->assertJsonPath('etudiants.par_diocese.1', ['diocese' => 'Non renseigné', 'total' => 1])
            ->assertJsonPath('comptes.prof.inactifs', 1)
            ->assertJsonPath('notes.saisies', 1)       // la note 0/20 compte
            ->assertJsonPath('notes.attendues', 2);    // 2 inscrits en L1 × 1 matière
    }
}
