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
        Schema::create('corps_enseignant', function (Blueprint $table) {
            $table->id();
            $table->string('nom_prenom', 255);
            $table->string('adresse', 255)->nullable();
            $table->string('email', 255)->unique()->nullable();
            $table->string('telephone', 20)->nullable();
            $table->foreignId('id_user_cession')
                  ->nullable()
                  ->constrained('user_cession')
                  ->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('corps_enseignant');
    }
};
