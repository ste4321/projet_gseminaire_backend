<?php

namespace Tests\Feature;

use App\Events\AnnonceMiseAJour;
use App\Models\Enseignant;
use App\Models\Etudiant;
use App\Models\EtudiantParcours;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Destinataires d'une annonce (étudiants d'un niveau ou de tous, enseignants, tout le monde) :
 * qui la voit, et qui est prévenu en direct à sa publication, modification et suppression.
 */
class AnnonceDestinatairesTest extends TestCase
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

    private function enseignant(): User
    {
        $user = $this->user('prof');
        Enseignant::create(['nom_prenom' => 'Père ' . $user->id, 'email' => $user->email, 'id_user_cession' => $user->id]);

        return $user;
    }

    /** Comptes prévenus par l'événement diffusé, pour une action donnée */
    private function prevenus(string $action): array
    {
        $ids = [];
        Event::assertDispatched(AnnonceMiseAJour::class, function (AnnonceMiseAJour $e) use ($action, &$ids) {
            if ($e->action === $action) {
                $ids = $e->idsUtilisateurs;
            }
            return $e->action === $action;
        });
        sort($ids);

        return $ids;
    }

    public function test_chacun_ne_voit_que_les_annonces_qui_lui_sont_destinees(): void
    {
        Event::fake([AnnonceMiseAJour::class]);
        [$annee, $niveaux] = $this->referentiel();
        $l1 = $this->etudiantInscrit($annee, $niveaux['L1']);
        $prof = $this->enseignant();

        $this->actingAs($this->admin(), 'sanctum');
        foreach ([
            ['Pour les L1', 'etudiants', $niveaux['L1']->id],
            ['Pour les L2', 'etudiants', $niveaux['L2']->id],
            ['Tous les étudiants', 'etudiants', ''],
            ['Enseignants', 'enseignants', $niveaux['L1']->id], // niveau ignoré : réservé aux étudiants
            ['Tout le monde', 'tous', ''],
        ] as [$titre, $destinataires, $niveau]) {
            $this->postJson('/api/annonces', ['titre' => $titre, 'description' => 'x', 'destinataires' => $destinataires, 'id_niveau' => $niveau])
                ->assertCreated();
        }
        $this->assertDatabaseHas('annonces', ['titre' => 'Enseignants', 'id_niveau' => null]);

        $titres = fn (User $u) => collect($this->actingAs($u, 'sanctum')->getJson('/api/annonces')->assertOk()->json())
            ->pluck('titre')->sort()->values()->all();

        $this->assertSame(['Pour les L1', 'Tous les étudiants', 'Tout le monde'], $titres($l1));
        $this->assertSame(['Enseignants', 'Tout le monde'], $titres($prof));
    }

    public function test_publication_previent_en_direct_les_seuls_destinataires_actifs(): void
    {
        Event::fake([AnnonceMiseAJour::class]);
        [$annee, $niveaux] = $this->referentiel();
        $l1 = $this->etudiantInscrit($annee, $niveaux['L1']);
        $l2 = $this->etudiantInscrit($annee, $niveaux['L2']);
        $this->etudiantInscrit($annee, $niveaux['L1'], actif: false); // compte désactivé : jamais prévenu
        $prof = $this->enseignant();
        $this->actingAs($this->admin(), 'sanctum');

        $this->postJson('/api/annonces', ['titre' => 'L1', 'description' => 'x', 'destinataires' => 'etudiants', 'id_niveau' => $niveaux['L1']->id]);
        $this->assertSame([$l1->id], $this->prevenus(AnnonceMiseAJour::CREEE));

        Event::fake([AnnonceMiseAJour::class]);
        $this->postJson('/api/annonces', ['titre' => 'Tous', 'description' => 'x', 'destinataires' => 'tous']);
        $this->assertSame(collect([$l1->id, $l2->id, $prof->id])->sort()->values()->all(), $this->prevenus(AnnonceMiseAJour::CREEE));
    }

    public function test_modification_et_suppression_previennent_anciens_et_nouveaux_destinataires(): void
    {
        Event::fake([AnnonceMiseAJour::class]);
        [$annee, $niveaux] = $this->referentiel();
        $etudiant = $this->etudiantInscrit($annee, $niveaux['L1']);
        $prof = $this->enseignant();
        $this->actingAs($this->admin(), 'sanctum');

        $id = $this->postJson('/api/annonces', ['titre' => 'A', 'description' => 'x', 'destinataires' => 'etudiants'])->json('id');

        // Des étudiants vers les enseignants : l'étudiant la voit disparaître, l'enseignant apparaître
        $this->putJson("/api/annonces/{$id}", ['titre' => 'A', 'description' => 'y', 'destinataires' => 'enseignants'])->assertOk();
        $this->assertSame(collect([$etudiant->id, $prof->id])->sort()->values()->all(), $this->prevenus(AnnonceMiseAJour::MODIFIEE));

        $this->deleteJson("/api/annonces/{$id}")->assertOk();
        $this->assertSame([$prof->id], $this->prevenus(AnnonceMiseAJour::SUPPRIMEE));
    }

    public function test_canal_prive_reserve_a_son_proprietaire(): void
    {
        // Les tests diffusent avec le pilote « null » : canaux enregistrés à nouveau sur un vrai pilote
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => ['driver' => 'reverb', 'key' => 'k', 'secret' => 's', 'app_id' => 'a', 'options' => []],
        ]);
        require base_path('routes/channels.php');
        $moi = $this->user('etudiant');
        $autre = $this->user('prof');
        $this->actingAs($moi, 'sanctum');

        $this->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-App.Models.User.{$moi->id}"])
            ->assertOk();
        $this->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-App.Models.User.{$autre->id}"])
            ->assertForbidden();
    }

    public function test_annuaire_des_enseignants_sans_donnees_internes_pour_les_etudiants(): void
    {
        $this->enseignant();
        $champs = array_keys($this->actingAs($this->user('etudiant'), 'sanctum')->getJson('/api/enseignants')->assertOk()->json('0'));

        $this->assertSame(['id', 'nom_prenom', 'email', 'telephone', 'adresse'], $champs);
    }
}
