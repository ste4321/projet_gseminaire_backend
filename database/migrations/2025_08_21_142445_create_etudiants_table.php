<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('etudiants', function (Blueprint $table) {
            $table->id();
            $table->string('nom_prenom');
            $table->string('matricule')->unique(); // ✅ Clé unique
            $table->string('diocese')->nullable();
            $table->foreignId('id_user_cession')
            ->nullable()
            ->constrained('user_cession')
            ->cascadeOnDelete();
            $table->string('email')->unique()->nullable();
            $table->string('telephone')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('etudiants');
    }
};
