<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // 1. Renommer la table
        Schema::rename('volume_horaire', 'matieres');

        // 2. Supprimer la colonne 'numero'
        Schema::table('matieres', function (Blueprint $table) {
            $table->dropColumn('numero');
        });

        // 3. Ajouter un champ 'id' auto-incrément et clé primaire
        Schema::table('matieres', function (Blueprint $table) {
            $table->id(); // équivalent à bigIncrements('id')
        });

        // 4. Renommer 'classe' en 'id_niveau'
        Schema::table('matieres', function (Blueprint $table) {
            $table->renameColumn('classe', 'id_niveau');
        });

        // 5. Ajouter la clé étrangère sur 'id_niveau'
        Schema::table('matieres', function (Blueprint $table) {
            $table->foreign('id_niveau')->references('id')->on('niveaux')->onDelete('cascade');
        });

        // 6. Supprimer 'semestre'
        Schema::table('matieres', function (Blueprint $table) {
            $table->dropColumn('semestre');
        });

        // 7. Ajouter 'code_matiere' et 'coefficient'
        Schema::table('matieres', function (Blueprint $table) {
            $table->string('code_matiere')->nullable();
            $table->float('coefficient')->nullable();
        });
    }

    public function down()
    {
        Schema::table('matieres', function (Blueprint $table) {
            $table->dropForeign(['id_niveau']);
            $table->dropColumn(['code_matiere', 'coefficient']);
            $table->renameColumn('id_niveau', 'classe');
            $table->integer('semestre')->nullable();
            $table->dropColumn('id');
            $table->integer('numero')->nullable();
        });

        Schema::rename('matieres', 'volume_horaire');
    }
};
