<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Programme par année académique.
 *
 * `matieres` devient le catalogue (nom, code, niveau, semestre ; coefficient et heures = valeurs proposées par défaut).
 * `programme_matieres` dit quelles matières sont enseignées une année donnée, avec le coefficient et les heures de
 * cette année-là. Relevés, publication, import et tableau de bord ne lisent plus que le programme de l'année :
 * ajouter, retirer une matière ou changer un coefficient ne modifie plus les années passées.
 *
 * Reprise : chaque matière existante est mise au programme de chaque année existante, avec ses valeurs actuelles,
 * pour que les relevés déjà calculés restent identiques.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programme_matieres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_annee_aca')->constrained('annee_aca')->cascadeOnDelete();
            // Retirer une matière du catalogue la retire des programmes (impossible si elle a des notes, voir notes.id_matiere)
            $table->foreignId('id_matiere')->constrained('matieres')->cascadeOnDelete();
            $table->decimal('coefficient', 6, 2);
            $table->unsignedInteger('heures')->default(0);
            $table->timestamps();

            $table->unique(['id_annee_aca', 'id_matiere']);
        });

        $maintenant = now();
        $annees = DB::table('annee_aca')->pluck('id');
        DB::table('matieres')->orderBy('id')->each(function ($m) use ($annees, $maintenant) {
            // coefficient était une chaîne libre : « 2,5 » ou vide sont ramenés à un nombre
            $coef = str_replace(',', '.', trim((string) $m->coefficient));
            $lignes = $annees->map(fn ($annee) => [
                'id_annee_aca' => $annee,
                'id_matiere'   => $m->id,
                'coefficient'  => is_numeric($coef) ? (float) $coef : 1,
                'heures'       => max(0, (int) $m->heures),
                'created_at'   => $maintenant,
                'updated_at'   => $maintenant,
            ])->all();
            if ($lignes) {
                DB::table('programme_matieres')->insert($lignes);
            }
        });

        // Supprimer une matière effaçait toutes ses notes, de toutes les années : désormais refusé par la base
        Schema::table('notes', function (Blueprint $table) {
            $table->dropForeign(['id_matiere']);
            $table->foreign('id_matiere')->references('id')->on('matieres')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->dropForeign(['id_matiere']);
            $table->foreign('id_matiere')->references('id')->on('matieres')->cascadeOnDelete();
        });

        Schema::dropIfExists('programme_matieres');
    }
};
