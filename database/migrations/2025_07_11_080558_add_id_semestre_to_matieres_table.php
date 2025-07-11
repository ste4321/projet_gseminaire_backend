<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('matieres', function (Blueprint $table) {
            $table->unsignedBigInteger('id_semestre')->nullable()->after('id_niveau');
            $table->foreign('id_semestre')->references('id')->on('semestres')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::table('matieres', function (Blueprint $table) {
            $table->dropForeign(['id_semestre']);
            $table->dropColumn('id_semestre');
        });
    }

};
