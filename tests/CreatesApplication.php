<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        // Garde-fou : les tests vident la base (RefreshDatabase) ; on refuse de démarrer
        // si ce n'est pas la base de test, avant toute migration.
        $database = $app['config']->get('database.connections.' . $app['config']->get('database.default') . '.database');
        if ($database !== 'db_gestionnaire_test') {
            throw new \RuntimeException("Tests interrompus : base « {$database} » au lieu de db_gestionnaire_test (voir phpunit.xml).");
        }

        return $app;
    }
}
