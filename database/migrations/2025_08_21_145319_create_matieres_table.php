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
        Schema::create('matieres', function (Blueprint $table) {
            $table->id();
            $table->string('matiere');
            $table->integer('heures');
            $table->foreignId('id_niveau')
            ->nullable()
            ->constrained('niveaux')
            ->cascadeOnDelete();
            $table->string('code_matiere');
            $table->string('coefficient');
            $table->timestamps();
            $table->foreignId('id_semestre')
            ->nullable()
            ->constrained('semestres')
            ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matieres');
    }
};
