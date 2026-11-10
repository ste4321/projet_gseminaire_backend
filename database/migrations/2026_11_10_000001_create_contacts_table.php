<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages du formulaire « Contactez-nous » du site vitrine (POST /api/postContact),
 * lus et suivis par l'administration (page « Messages » de l'application de gestion).
 * lu_le / repondu_le : null tant que le message n'a pas été lu / n'a pas reçu de réponse.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contacts')) {
            return; // base importée qui aurait déjà la table
        }

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->text('message');
            $table->timestamp('lu_le')->nullable()->index();
            $table->timestamp('repondu_le')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
