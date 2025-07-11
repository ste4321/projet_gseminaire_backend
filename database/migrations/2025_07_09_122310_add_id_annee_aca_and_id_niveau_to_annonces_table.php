<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('annonces', function (Blueprint $table) {
            $table->foreignId('id_annee_aca')->constrained('annee_aca')->onDelete('cascade');
            $table->foreignId('id_niveau')->constrained('niveaux')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::table('annonces', function (Blueprint $table) {
            $table->dropForeign(['id_annee_aca']);
            $table->dropForeign(['id_niveau']);
            $table->dropColumn(['id_annee_aca', 'id_niveau']);
        });
    }

};
