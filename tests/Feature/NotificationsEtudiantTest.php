<?php

namespace Tests\Feature;

use App\Events\EmploiDuTempsMisAJour;
use App\Events\NotesPubliees;
use App\Models\Etudiant;
use App\Models\EtudiantParcours;
use App\Models\Semestre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Notifications en direct de l'espace étudiant : notes publiées (ou retirées), emploi du temps mis à jour.
 */
class NotificationsEtudiantTest extends TestCase
{
    use RefreshDatabase;

    private function etudiantInscrit($annee, $niveau, bool $actif = true): User
    {
        $user = $this->user('etudiant', $actif);
        $etudiant = Etudiant::create(['matricule' => 'M' . $user->id, 'nom_prenom' => 'Étudiant ' . $user->id, 'id_user_cession' => $user->id]);
        EtudiantParcours::create([
            'id_etudiant' => $etudiant->id, 'id_annee_aca' => $annee->id, 'id_niveau' => $niveau->id,
            'statut' => 'inscrit', 'date_inscription' => now(), 'matricule' => $etudiant->matricule,
        ]);

        return $user;
    }

    private static function tries(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    public function test_publication_des_notes_previent_les_etudiants_du_niveau(): void
    {
        Event::fake([NotesPubliees::class]);
        [$annee, $niveaux] = $this->referentiel();
        $semestre = Semestre::create(['semestre' => 'Semestre 1', 'code_semestre' => 'S1']);
        $l1a = $this->etudiantInscrit($annee, $niveaux['L1']);
        $l1b = $this->etudiantInscrit($annee, $niveaux['L1']);
        $this->etudiantInscrit($annee, $niveaux['L1'], actif: false); // compte désactivé : pas prévenu
        $this->etudiantInscrit($annee, $niveaux['L2']); // autre niveau : pas prévenu
        $cible = ['id_annee_aca' => $annee->id, 'id_niveau' => $niveaux['L1']->id, 'id_semestre' => $semestre->id];
        $this->actingAs($this->admin(), 'sanctum');

        $this->postJson('/api/notes/publications', $cible + ['code_confirmation' => $this->codeConfirmation('publier_notes')])->assertCreated();
        Event::assertDispatched(NotesPubliees::class, fn (NotesPubliees $e) => $e->action === NotesPubliees::PUBLIEES
            && $e->semestre === 'Semestre 1'
            && self::tries($e->idsUtilisateurs) === self::tries([$l1a->id, $l1b->id])
            && $e->broadcastWith() === ['action' => 'publiees', 'semestre' => 'Semestre 1']);

        // Déjà publié : pas de seconde notification
        Event::fake([NotesPubliees::class]);
        $this->postJson('/api/notes/publications', $cible + ['code_confirmation' => $this->codeConfirmation('publier_notes')])->assertOk();
        Event::assertNotDispatched(NotesPubliees::class);

        // Retrait : les relevés se mettent à jour
        $this->deleteJson('/api/notes/publications', $cible)->assertOk();
        Event::assertDispatched(NotesPubliees::class, fn (NotesPubliees $e) => $e->action === NotesPubliees::RETIREES);
    }

    public function test_emploi_du_temps_previent_les_etudiants_inscrits_cette_annee(): void
    {
        Event::fake([EmploiDuTempsMisAJour::class]);
        Storage::fake('public');
        [$annee, $niveaux] = $this->referentiel();
        $l1 = $this->etudiantInscrit($annee, $niveaux['L1']);
        $l3 = $this->etudiantInscrit($annee, $niveaux['L3']);
        $this->actingAs($this->admin(), 'sanctum');

        $id = $this->postJson('/api/emplois', ['image' => UploadedFile::fake()->image('edt.jpg'), 'semestre' => 2])->assertCreated()->json('id');
        $this->postJson("/api/emplois/{$id}/upload", ['image' => UploadedFile::fake()->image('edt2.jpg')])->assertOk();
        $this->deleteJson("/api/emplois/{$id}")->assertOk();

        foreach ([EmploiDuTempsMisAJour::AJOUTE, EmploiDuTempsMisAJour::REMPLACE, EmploiDuTempsMisAJour::SUPPRIME] as $action) {
            Event::assertDispatched(EmploiDuTempsMisAJour::class, fn (EmploiDuTempsMisAJour $e) => $e->action === $action
                && $e->semestre === 2
                && self::tries($e->idsUtilisateurs) === self::tries([$l1->id, $l3->id]));
        }
    }

    public function test_publication_reussie_meme_si_le_serveur_websocket_est_arrete(): void
    {
        // Serveur Reverb injoignable (port fermé) : l'image est quand même ajoutée
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => [
                'driver' => 'reverb', 'key' => 'k', 'secret' => 's', 'app_id' => 'a',
                'options' => ['host' => '127.0.0.1', 'port' => 1, 'scheme' => 'http', 'useTLS' => false],
                'client_options' => ['timeout' => 2, 'connect_timeout' => 2],
            ],
        ]);
        Storage::fake('public');
        [$annee, $niveaux] = $this->referentiel();
        $this->etudiantInscrit($annee, $niveaux['L1']);
        $this->actingAs($this->admin(), 'sanctum');

        $this->postJson('/api/emplois', ['image' => UploadedFile::fake()->image('edt.jpg'), 'semestre' => 1])->assertCreated();
    }
}
