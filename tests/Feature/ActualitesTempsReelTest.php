<?php

namespace Tests\Feature;

use App\Events\ActualiteMiseAJour;
use App\Models\Info;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ActualitesTempsReelTest extends TestCase
{
    use RefreshDatabase;

    public function test_publication_modification_suppression_diffusees(): void
    {
        Event::fake([ActualiteMiseAJour::class]);
        $this->actingAs($this->admin(), 'sanctum');

        $id = $this->postJson('/api/infos', ['auteur' => 'Père Rodolphe', 'titre' => 'Fête de la Toussaint', 'description' => 'Messe à 9 h',
            'code_confirmation' => $this->codeConfirmation('publier_actualite')])
            ->assertCreated()
            ->json('id');
        $this->putJson("/api/infos/{$id}", ['auteur' => 'Père Rodolphe', 'titre' => 'Fête de la Toussaint (horaire)', 'description' => 'Messe à 10 h'])
            ->assertOk();
        $this->deleteJson("/api/infos/{$id}")->assertOk();

        Event::assertDispatched(ActualiteMiseAJour::class, fn ($e) => $e->action === 'creee' && $e->info->titre === 'Fête de la Toussaint');
        Event::assertDispatched(ActualiteMiseAJour::class, fn ($e) => $e->action === 'modifiee');
        Event::assertDispatched(ActualiteMiseAJour::class, fn ($e) => $e->action === 'supprimee' && $e->broadcastWith() === ['action' => 'supprimee', 'info' => ['id' => $id]]);
    }

    public function test_canal_public_et_contenu_limite_aux_champs_publics(): void
    {
        $info = Info::create(['auteur' => 'A', 'titre' => 'T', 'description' => 'D']);
        $event = new ActualiteMiseAJour(ActualiteMiseAJour::CREEE, $info);

        $this->assertSame('actualites', $event->broadcastOn()->name);
        $this->assertSame('actualite.maj', $event->broadcastAs());
        $this->assertEqualsCanonicalizing(
            ['id', 'auteur', 'titre', 'description', 'created_at', 'updated_at'],
            array_keys($event->broadcastWith()['info'])
        );
    }

    public function test_publication_reussie_meme_si_le_serveur_websocket_est_arrete(): void
    {
        // Serveur Reverb injoignable (port fermé)
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => [
                'driver' => 'reverb', 'key' => 'k', 'secret' => 's', 'app_id' => 'a',
                'options' => ['host' => '127.0.0.1', 'port' => 1, 'scheme' => 'http', 'useTLS' => false],
                'client_options' => ['timeout' => 2, 'connect_timeout' => 2],
            ],
        ]);
        $this->actingAs($this->admin(), 'sanctum');

        $code = $this->codeConfirmation('publier_actualite');
        $this->postJson('/api/infos', ['auteur' => 'A', 'titre' => 'Hors ligne', 'description' => 'D', 'code_confirmation' => $code])->assertCreated();
        $this->assertSame(1, Info::where('titre', 'Hors ligne')->count());
    }
}
