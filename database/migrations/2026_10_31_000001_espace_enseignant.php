<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espace enseignant.
 *
 * - programme_matieres.id_enseignant : qui enseigne la matière cette année-là. C'est ce lien qui donne à
 *   l'enseignant le droit de saisir les notes et de déposer les leçons de la matière.
 * - lecons : supports de cours déposés par l'enseignant, visibles par les étudiants une fois publiés
 *   (publie_le vide = brouillon).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programme_matieres', function (Blueprint $table) {
            $table->foreignId('id_enseignant')->nullable()->after('id_matiere')
                ->constrained('corps_enseignant')->nullOnDelete();
        });

        Schema::create('lecons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_annee_aca')->constrained('annee_aca')->restrictOnDelete();
            $table->foreignId('id_matiere')->constrained('matieres')->restrictOnDelete();
            // Auteur : la leçon reste en ligne si la fiche de l'enseignant est supprimée
            $table->foreignId('id_enseignant')->nullable()->constrained('corps_enseignant')->nullOnDelete();
            $table->string('titre');
            $table->text('contenu')->nullable();
            $table->string('fichier')->nullable();
            $table->string('nom_fichier')->nullable();
            $table->timestamp('publie_le')->nullable();
            $table->timestamps();

            $table->index(['id_annee_aca', 'id_matiere']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lecons');

        Schema::table('programme_matieres', function (Blueprint $table) {
            $table->dropConstrainedForeignId('id_enseignant');
        });
    }
};
