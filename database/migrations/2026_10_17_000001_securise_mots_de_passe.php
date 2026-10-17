<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fin du stockage des mots de passe en clair.
 *
 * - user_cession.must_change_password : le mot de passe est provisoire,
 *   l'utilisateur doit le remplacer avant d'accéder au site.
 * - suppression de la table « passwords » (mots de passe en clair).
 *
 * Les comptes étudiants et enseignants existants ont reçu un mot de passe généré :
 * ils devront le changer à leur prochaine connexion. L'admin peut le réinitialiser
 * depuis le Portail si l'utilisateur ne le connaît pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_cession', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('password_changed_at')->nullable();
        });

        DB::table('user_cession')->where('role', '!=', 'admin')->update(['must_change_password' => true]);

        Schema::dropIfExists('passwords');
    }

    public function down(): void
    {
        // Les mots de passe en clair supprimés ne peuvent pas être restaurés : seule la structure est recréée
        Schema::create('passwords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_user_cession')->constrained('user_cession')->onDelete('cascade');
            $table->string('password');
            $table->string('psw_scrypt');
            $table->timestamps();
        });

        Schema::table('user_cession', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'password_changed_at']);
        });
    }
};
