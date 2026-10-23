<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Destinataires d'une annonce :
 * - etudiants   : les étudiants de l'année (d'un niveau, ou de tous si id_niveau est NULL)
 * - enseignants : les enseignants uniquement
 * - tous        : les étudiants de l'année et les enseignants
 * Les annonces existantes s'adressaient aux étudiants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('annonces', function (Blueprint $table) {
            $table->string('destinataires', 20)->default('etudiants')->after('fichier');
        });
    }

    public function down(): void
    {
        Schema::table('annonces', function (Blueprint $table) {
            $table->dropColumn('destinataires');
        });
    }
};
