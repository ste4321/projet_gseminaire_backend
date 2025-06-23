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
        Schema::create('edts', function (Blueprint $table) {
            $table->id();
            $table->string('image_url');
            $table->tinyInteger('semestre'); // 1 ou 2
            $table->string('updated_by')->nullable(); // <- Email de l'utilisateur ayant modifié
            $table->timestamps(); // created_at, updated_at
        });
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('edts');
    }
};
