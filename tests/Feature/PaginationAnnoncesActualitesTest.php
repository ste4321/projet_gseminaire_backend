<?php

namespace Tests\Feature;

use App\Models\Annonce;
use App\Models\Etudiant;
use App\Models\EtudiantParcours;
use App\Models\Info;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Pagination par le serveur des annonces (page Annonces, cloche) et des actualités (site vitrine, administration) :
 * seules les lignes affichées transitent, quel que soit le nombre d'années d'archives.
 */
class PaginationAnnoncesActualitesTest extends TestCase
{
    use RefreshDatabase;

    private function annonce(string $titre, $annee, array $attributs = []): Annonce
    {
        return Annonce::create($attributs + [
            'titre' => $titre, 'description' => 'Contenu', 'expediteur' => 'Administration',
            'destinataires' => Annonce::ETUDIANTS, 'id_annee_aca' => $annee->id,
        ]);
    }

    public function test_page_d_annonces_filtree_par_le_serveur(): void
    {
        [$annee, $niveaux] = $this->referentiel();
        foreach (range(1, 11) as $i) {
            $this->annonce("Annonce $i", $annee);
        }
        $this->annonce('Retraite des L1', $annee, ['id_niveau' => $niveaux['L1']->id]);
        $this->annonce('Conseil des professeurs', $annee, ['destinataires' => Annonce::ENSEIGNANTS]);
        $this->annonce('Fête patronale', $annee, ['destinataires' => Annonce::TOUS]);

        $this->actingAs($this->admin(), 'sanctum');

        $page = $this->getJson('/api/annonces?page=2&per_page=8')->assertOk();
        $this->assertSame(2, $page->json('page'));
        $this->assertSame(2, $page->json('pages'));
        $this->assertSame(14, $page->json('total'));
        $this->assertCount(6, $page->json('data'));

        $titres = fn (string $params) => collect($this->getJson("/api/annonces?page=1&$params")->assertOk()->json('data'))
            ->pluck('titre')->all();
        $this->assertSame(['Retraite des L1'], $titres('destinataires=niveau:' . $niveaux['L1']->id));
        $this->assertSame(['Conseil des professeurs'], $titres('destinataires=enseignants'));
        $this->assertSame(['Fête patronale'], $titres('destinataires=tous'));
        $this->assertCount(11, $titres('destinataires=etudiants&per_page=50')); // tous niveaux : sans niveau précis
        $this->assertSame(['Retraite des L1'], $titres('q=retraite'));

        // Total sans filtre, pour afficher « 1 sur 14 »
        $this->assertSame(14, $this->getJson('/api/annonces?page=1&q=retraite')->json('total_tout'));

        // Choix de destinataires inconnu : refusé
        $this->getJson('/api/annonces?page=1&destinataires=n_importe_quoi')->assertStatus(422);
    }

    public function test_annonces_recentes_et_ancien_format(): void
    {
        [$annee] = $this->referentiel();
        foreach (range(1, 5) as $i) {
            $this->annonce("Annonce $i", $annee)->forceFill(['created_at' => now()->subDays(10 - $i)])->save();
        }
        $this->actingAs($this->admin(), 'sanctum');

        $recentes = $this->getJson('/api/annonces?recentes=2')->assertOk()->json();
        $this->assertSame(['Annonce 5', 'Annonce 4'], array_column($recentes, 'titre'));

        // Sans paramètre : toutes, comme avant
        $this->assertCount(5, $this->getJson('/api/annonces')->assertOk()->json());
    }

    public function test_un_etudiant_ne_pagine_que_ses_annonces(): void
    {
        [$annee, $niveaux] = $this->referentiel();
        $user = $this->user('etudiant');
        $etudiant = Etudiant::create(['matricule' => 'M1', 'nom_prenom' => 'Jean', 'id_user_cession' => $user->id]);
        EtudiantParcours::create([
            'id_etudiant' => $etudiant->id, 'id_annee_aca' => $annee->id, 'id_niveau' => $niveaux['L2']->id,
            'statut' => 'inscrit', 'date_inscription' => now(), 'matricule' => 'M1',
        ]);
        $this->annonce('Pour les L2', $annee, ['id_niveau' => $niveaux['L2']->id]);
        $this->annonce('Pour les L1', $annee, ['id_niveau' => $niveaux['L1']->id]);
        $this->annonce('Pour les enseignants', $annee, ['destinataires' => Annonce::ENSEIGNANTS]);

        $page = $this->actingAs($user, 'sanctum')->getJson('/api/annonces?page=1')->assertOk();
        $this->assertSame(['Pour les L2'], array_column($page->json('data'), 'titre'));
        $this->assertSame(1, $page->json('total_tout'));

        // Étudiant sans inscription : page vide, au même format
        $sansInscription = $this->user('etudiant');
        $this->actingAs($sansInscription, 'sanctum')->getJson('/api/annonces?page=1')
            ->assertOk()->assertJson(['data' => [], 'total' => 0, 'pages' => 0]);
    }

    public function test_actualites_par_page_et_recherche(): void
    {
        foreach (range(1, 8) as $i) {
            Info::create(['auteur' => 'Père Recteur', 'titre' => "Actualité $i", 'description' => 'Texte'])
                ->forceFill(['created_at' => now()->subDays(10 - $i)])->save();
        }
        Info::create(['auteur' => 'Économat', 'titre' => 'Journée portes ouvertes', 'description' => 'Visite du séminaire']);

        // Public : site vitrine
        $page = $this->getJson('/api/infos?page=1&per_page=6')->assertOk();
        $this->assertSame('Journée portes ouvertes', $page->json('data.0.titre'));
        $this->assertCount(6, $page->json('data'));
        $this->assertSame(2, $page->json('pages'));
        $this->assertSame(9, $page->json('total'));

        $this->assertCount(3, $this->getJson('/api/infos?page=2&per_page=6')->json('data'));
        $this->assertSame(['Journée portes ouvertes'], array_column($this->getJson('/api/infos?page=1&q=portes')->json('data'), 'titre'));

        // Sans paramètre : toutes, comme avant (versions du site déjà en ligne)
        $this->assertCount(9, $this->getJson('/api/infos')->assertOk()->json());
    }

    public function test_une_actualite_par_son_lien(): void
    {
        $info = Info::create(['auteur' => 'Père Recteur', 'titre' => 'Ordinations', 'description' => 'Texte']);

        $this->getJson("/api/infos/{$info->id}")->assertOk()->assertJson(['titre' => 'Ordinations']);
        $this->getJson('/api/infos/999999')->assertNotFound();
    }
}
