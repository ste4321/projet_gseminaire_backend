<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('corps_enseignant', function (Blueprint $table) {
            // Renommer la colonne numero en id
            $table->renameColumn('mail', 'email');


            // Ajouter nouvelles colonnes
            $table->foreignId('id_user_cession')->nullable()->unique()->constrained('user_cession')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::table('corps_enseignant', function (Blueprint $table) {
            // Supprimer la clé étrangère et la colonne
            $table->dropForeign(['id_user_cession']);
            $table->dropColumn('id_user_cession');

            // Renommer la colonne email en mail
            $table->renameColumn('email', 'mail');
        });
    }

};
