<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publication des notes : un étudiant ne voit les notes d'un semestre
 * qu'une fois ce semestre publié par l'administration pour son année et son niveau.
 * Une ligne = un semestre publié ; retirer la publication supprime la ligne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publications_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_annee_aca')->constrained('annee_aca')->cascadeOnDelete();
            $table->foreignId('id_niveau')->constrained('niveaux')->cascadeOnDelete();
            $table->foreignId('id_semestre')->constrained('semestres')->cascadeOnDelete();
            $table->foreignId('publie_par')->nullable()->constrained('user_cession')->nullOnDelete();
            $table->timestamps();

            $table->unique(['id_annee_aca', 'id_niveau', 'id_semestre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publications_notes');
    }
};
