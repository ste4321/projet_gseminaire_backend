<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('etudiants', function (Blueprint $table) {
            // Renommer la colonne numero en id
            $table->renameColumn('numero', 'id');

            // Supprimer les colonnes annee et idannee_aca
            $table->dropColumn(['annee', 'idannee_aca']);

            // Ajouter nouvelles colonnes
            $table->foreignId('id_user_cession')->nullable()->unique()->constrained('user_cession')->onDelete('cascade');
            $table->string('email')->nullable();
            $table->string('telephone')->nullable();
        });
    }

    public function down()
    {
        Schema::table('etudiants', function (Blueprint $table) {
            // Restaurer les colonnes supprimées
            $table->dropForeign(['id_user_cession']);
            $table->dropColumn(['id_user_cession', 'email', 'telephone']);

            $table->string('annee')->nullable();
            $table->unsignedBigInteger('idannee_aca')->nullable();

            // Renommer de nouveau id en numero
            $table->renameColumn('id', 'numero');
        });
    }
};
