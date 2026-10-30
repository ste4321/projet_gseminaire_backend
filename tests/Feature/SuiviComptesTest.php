<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SuiviComptesTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_connexion_est_datee_sans_toucher_a_updated_at(): void
    {
        $user = $this->user('etudiant');
        $modifie = $user->updated_at;
        $this->assertNull($user->derniere_connexion_at);

        Carbon::setTestNow(now()->addHour()->startOfSecond());
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'motdepasse'])->assertOk();

        $user->refresh();
        $this->assertTrue($user->derniere_connexion_at->equalTo(now()));
        $this->assertTrue($user->updated_at->equalTo($modifie));
        Carbon::setTestNow();
    }

    public function test_une_consultation_par_l_administration_ne_compte_pas_comme_connexion(): void
    {
        $etudiant = $this->user('etudiant');
        $jeton = $etudiant->createToken('consultation', ['consultation'])->plainTextToken;

        $this->withToken($jeton)->postJson('/api/refresh');

        $this->assertNull($etudiant->refresh()->derniere_connexion_at);
    }

    public function test_age_du_mot_de_passe_provisoire(): void
    {
        $comptes = app(AccountService::class);
        [$user] = $comptes->create('etudiant', null, 'E001');
        $this->assertNotNull($user->provisoire_depuis);

        // Activation : la date du provisoire ne bouge pas
        Carbon::setTestNow(now()->addDays(10)->startOfSecond());
        $depuis = $user->refresh()->provisoire_depuis;
        $user->update(['is_verified' => true]);
        $this->assertTrue($user->refresh()->provisoire_depuis->equalTo($depuis));

        // Réinitialisation : nouveau provisoire, nouvelle date
        $comptes->resetPassword($user);
        $this->assertTrue($user->refresh()->provisoire_depuis->equalTo(now()));

        // Mot de passe personnel : plus de date
        $comptes->changePassword($user, 'MonNouveauMotDePasse2026');
        $this->assertNull($user->refresh()->provisoire_depuis);
        Carbon::setTestNow();
    }

    public function test_statut_en_ligne_selon_le_signal_de_presence_et_la_session(): void
    {
        $admin = $this->admin();
        $etudiant = $this->user('etudiant');
        $statut = function () use ($admin, $etudiant) {
            $this->actingAs($admin, 'sanctum');
            return collect($this->getJson('/api/user-cessions/presence?role=etudiant')->assertOk()->json())->firstWhere('id', $etudiant->id);
        };

        $jeton = $this->postJson('/api/login', ['email' => $etudiant->email, 'password' => 'motdepasse'])->json('access_token');
        $this->assertTrue($statut()['en_ligne']);

        // Application fermée sans se déconnecter : hors ligne une fois le signal trop ancien
        Carbon::setTestNow(now()->addMinutes(5));
        $this->assertFalse($statut()['en_ligne']);

        // Application rouverte : le signal la remet en ligne
        $this->app['auth']->forgetGuards();
        $this->withToken($jeton)->postJson('/api/presence')->assertNoContent();
        $this->assertTrue($statut()['en_ligne']);

        // Déconnexion : hors ligne aussitôt, la date « vu » reste
        $this->app['auth']->forgetGuards();
        $this->withToken($jeton)->postJson('/api/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $resultat = $statut();
        $this->assertFalse($resultat['en_ligne']);
        $this->assertNotNull($resultat['derniere_activite_at']);
        Carbon::setTestNow();
    }

    public function test_une_consultation_ne_met_pas_en_ligne(): void
    {
        $etudiant = $this->user('etudiant');
        $jeton = $etudiant->createToken('consultation', ['consultation'])->plainTextToken;

        $this->withToken($jeton)->postJson('/api/presence');

        $this->assertNull($etudiant->refresh()->derniere_activite_at);
    }

    public function test_la_liste_des_comptes_expose_les_deux_dates(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        [$user] = app(AccountService::class)->create('etudiant', null, 'E002');

        $compte = collect($this->getJson('/api/user-cessions?role=etudiant')->assertOk()->json())->firstWhere('id', $user->id);
        $this->assertArrayHasKey('derniere_connexion_at', $compte);
        $this->assertNotNull($compte['provisoire_depuis']);
    }
}
