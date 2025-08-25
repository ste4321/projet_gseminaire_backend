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
        Schema::create('etudiant_parcours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_annee_aca')->constrained('annee_aca')->onDelete('cascade');
            $table->foreignId('id_niveau')->constrained('niveaux')->onDelete('cascade');
            $table->string('statut')->nullable();
            $table->date('date_inscription');
            $table->foreignId('id_etudiant')->constrained('etudiants')->onDelete('cascade');
            $table->string('matricule');
            $table->foreign('matricule')
            ->references('matricule')
            ->on('etudiants')
            ->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('etudiant_parcours');
    }
};
