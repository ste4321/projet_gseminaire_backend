<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PostgreSQL ne crée pas d'index sur les clés étrangères : sans eux, chaque liste filtrée
 * par année/niveau et chaque relevé de notes parcourt la table entière.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etudiant_parcours', function (Blueprint $table) {
            $table->index(['id_annee_aca', 'id_niveau'], 'etudiant_parcours_annee_niveau_index');
            $table->index('id_etudiant', 'etudiant_parcours_etudiant_index');
        });

        Schema::table('notes', function (Blueprint $table) {
            $table->index(['id_etudiant_parcours', 'id_matiere'], 'notes_parcours_matiere_index');
        });
    }

    public function down(): void
    {
        Schema::table('etudiant_parcours', function (Blueprint $table) {
            $table->dropIndex('etudiant_parcours_annee_niveau_index');
            $table->dropIndex('etudiant_parcours_etudiant_index');
        });

        Schema::table('notes', function (Blueprint $table) {
            $table->dropIndex('notes_parcours_matiere_index');
        });
    }
};
