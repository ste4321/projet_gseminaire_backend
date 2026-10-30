<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Présence (statut « En ligne » de la page « Comptes et accès ») :
 * derniere_activite_at est rafraîchie par l'application tant qu'elle est ouverte (POST /api/presence).
 * Un compte est en ligne si cette date est récente et qu'il a une session ouverte (voir User::estEnLigne).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_cession', function (Blueprint $table) {
            $table->timestamp('derniere_activite_at')->nullable();
        });

        DB::table('user_cession')->whereNotNull('derniere_connexion_at')->update(['derniere_activite_at' => DB::raw('derniere_connexion_at')]);
    }

    public function down(): void
    {
        Schema::table('user_cession', function (Blueprint $table) {
            $table->dropColumn('derniere_activite_at');
        });
    }
};
