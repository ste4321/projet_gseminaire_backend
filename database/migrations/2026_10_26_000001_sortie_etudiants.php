<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Départ d'un étudiant (diplômé, abandon, transfert, exclusion) : le dossier n'est jamais supprimé
 * (notes et bulletins restent consultables), il porte la date et le motif de sortie.
 * Le statut de chaque inscription (etudiant_parcours.statut) décrit l'issue de l'année :
 * inscrit, admis, redoublant, diplome, abandon, transfere, exclu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etudiants', function (Blueprint $table) {
            $table->date('date_sortie')->nullable();
            $table->string('motif_sortie', 500)->nullable();
            $table->index('date_sortie');
        });

        Schema::table('etudiant_parcours', function (Blueprint $table) {
            $table->index(['id_annee_aca', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::table('etudiant_parcours', function (Blueprint $table) {
            $table->dropIndex(['id_annee_aca', 'statut']);
        });

        Schema::table('etudiants', function (Blueprint $table) {
            $table->dropIndex(['date_sortie']);
            $table->dropColumn(['date_sortie', 'motif_sortie']);
        });
    }
};
