<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Une annonce sans niveau (id_niveau NULL) s'adresse à tous les étudiants de l'année.
 * Évite de publier trois fois la même annonce (L1, L2, L3).
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQL direct (PostgreSQL) : ->change() exigerait doctrine/dbal sous Laravel 10
        DB::statement('ALTER TABLE annonces ALTER COLUMN id_niveau DROP NOT NULL');
    }

    public function down(): void
    {
        // Les annonces « tous niveaux » ne peuvent pas revenir à un niveau précis : elles sont supprimées
        DB::table('annonces')->whereNull('id_niveau')->delete();
        DB::statement('ALTER TABLE annonces ALTER COLUMN id_niveau SET NOT NULL');
    }
};
