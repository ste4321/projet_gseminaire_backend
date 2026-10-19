<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le code matière devient facultatif (seul l'import des notes par fichier en a besoin).
 * SQL direct : la modification de colonne via le schéma demanderait doctrine/dbal (Laravel 10).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE matieres ALTER COLUMN code_matiere DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE matieres SET code_matiere = CONCAT('MAT', id) WHERE code_matiere IS NULL");
        DB::statement('ALTER TABLE matieres ALTER COLUMN code_matiere SET NOT NULL');
    }
};
