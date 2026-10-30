<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suivi des comptes (page « Comptes et accès ») :
 * - derniere_connexion_at : dernière connexion réussie (ou prolongation de session) de la personne elle-même ;
 *   une consultation de son espace par l'administration ne compte pas.
 * - provisoire_depuis : date depuis laquelle le mot de passe est provisoire (null s'il est personnel),
 *   tenue à jour par le modèle User. Comptes déjà provisoires : date de leur dernière modification
 *   (souvent l'activation), faute de mieux.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_cession', function (Blueprint $table) {
            $table->timestamp('derniere_connexion_at')->nullable();
            $table->timestamp('provisoire_depuis')->nullable();
        });

        DB::table('user_cession')->where('must_change_password', true)->update(['provisoire_depuis' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('user_cession', function (Blueprint $table) {
            $table->dropColumn(['derniere_connexion_at', 'provisoire_depuis']);
        });
    }
};
