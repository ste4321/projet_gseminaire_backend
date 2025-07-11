<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matieres', function (Blueprint $table) {
            $table->timestamps(); // Ajoute created_at et updated_at
        });
    }

    public function down(): void
    {
        Schema::table('matieres', function (Blueprint $table) {
            $table->dropTimestamps(); // Supprime created_at et updated_at
        });
    }
};
