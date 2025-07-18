<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('etudiant_parcours', function (Blueprint $table) {
            // ✅ Supprimer la contrainte si elle existe
            DB::statement('ALTER TABLE etudiant_parcours DROP CONSTRAINT IF EXISTS etudiant_parcours_id_etudaint_foreign');
        });

        Schema::table('etudiant_parcours', function (Blueprint $table) {
            // ✅ Ajouter la colonne matricule si elle n'existe pas
            if (!Schema::hasColumn('etudiant_parcours', 'matricule')) {
                $table->string('matricule')->after('id_etudiant');
            }
        });

        // ✅ Ajouter la foreign key sur matricule (en dehors du bloc précédent pour éviter conflits)
        Schema::table('etudiant_parcours', function (Blueprint $table) {
            $table->foreign('matricule')
                ->references('matricule')
                ->on('etudiants')
                ->onDelete('cascade');
        });

        // ✅ Supprimer la colonne fautive si elle existe
        Schema::table('etudiant_parcours', function (Blueprint $table) {
            if (Schema::hasColumn('etudiant_parcours', 'id_etudaint')) {
                $table->dropColumn('id_etudaint');
            }
        });
    }

    public function down(): void
    {
        Schema::table('etudiant_parcours', function (Blueprint $table) {
            // Supprimer la foreign key et la colonne matricule
            $table->dropForeign(['matricule']);
            $table->dropColumn('matricule');

            // Restaurer la colonne id_etudaint si besoin
            if (!Schema::hasColumn('etudiant_parcours', 'id_etudaint')) {
                $table->foreignId('id_etudaint')
                      ->nullable()
                      ->constrained('etudiants')
                      ->onDelete('cascade');
            }
        });
    }
};
