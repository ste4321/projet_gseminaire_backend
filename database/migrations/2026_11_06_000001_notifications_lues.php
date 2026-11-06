<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications lues (cloche de l'en-tête), rattachées au compte et non plus au navigateur :
 * ce qui a été lu sur un appareil ne réapparaît pas sur un autre. Voir NotificationsLuesController.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_cession', function (Blueprint $table) {
            $table->json('notifications_lues')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_cession', function (Blueprint $table) {
            $table->dropColumn('notifications_lues');
        });
    }
};
