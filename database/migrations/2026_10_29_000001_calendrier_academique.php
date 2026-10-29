<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Calendrier académique du site vitrine, saisi depuis l'administration (projet_gseminaire_gestion).
 * Un calendrier par année (« 2025-2026 »), en brouillon tant qu'il n'est pas publié ; le site affiche
 * le plus récent des calendriers publiés. Les bornes des semestres sont facultatives : sans elles,
 * le site prend la première et la dernière date des événements du semestre.
 * Événement : un jour (date_debut), une période (« du … au … », liaison « du ») ou deux jours (« … et … », liaison « et »).
 * Reprend le calendrier 2025-2026 jusqu'ici écrit en dur dans le site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendriers', function (Blueprint $table) {
            $table->id();
            $table->string('annee', 9)->unique();
            $table->boolean('publie')->default(false);
            $table->date('s1_debut')->nullable();
            $table->date('s1_fin')->nullable();
            $table->date('s2_debut')->nullable();
            $table->date('s2_fin')->nullable();
            $table->timestamps();
        });

        Schema::create('calendrier_evenements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendrier_id')->constrained('calendriers')->cascadeOnDelete();
            $table->unsignedTinyInteger('semestre');
            $table->date('date_debut');
            $table->date('date_fin')->nullable();
            $table->string('liaison', 3)->nullable();
            $table->string('libelle');
            $table->timestamps();
            $table->index(['calendrier_id', 'date_debut']);
        });

        $this->reprendreCalendrier2025();
    }

    public function down(): void
    {
        Schema::dropIfExists('calendrier_evenements');
        Schema::dropIfExists('calendriers');
    }

    private function reprendreCalendrier2025(): void
    {
        $maintenant = now();
        $id = DB::table('calendriers')->insertGetId([
            'annee' => '2025-2026', 'publie' => true,
            's1_debut' => '2025-09-25', 's1_fin' => '2026-02-07',
            's2_debut' => '2026-02-09', 's2_fin' => '2026-06-19',
            'created_at' => $maintenant, 'updated_at' => $maintenant,
        ]);

        $evenements = [
            [1, '2025-09-25', null, null, 'Rentrée des internes'],
            [1, '2025-09-29', null, null, 'Réunion de tous les étudiants à 8 h, puis Messe du Saint-Esprit à 10 h'],
            [1, '2025-10-26', null, null, 'Fête patronale et Ordre mineur'],
            [1, '2025-11-01', null, null, 'La Toussaint (pas de cours)'],
            [1, '2025-12-17', null, null, 'Vacances de Noël (l’après-midi)'],
            [1, '2026-01-03', null, null, 'Rentrée des internes'],
            [1, '2026-01-05', null, null, 'Reprise des cours'],
            [1, '2026-01-22', '2026-02-04', 'du', 'Examens semestriels'],
            [1, '2026-02-06', '2026-02-07', 'et', 'Symposium'],
            [2, '2026-02-09', null, null, 'Début des cours'],
            [2, '2026-03-26', null, null, 'Vacances de Pâques'],
            [2, '2026-04-09', null, null, 'Rentrée des internes'],
            [2, '2026-04-10', null, null, 'Reprise des cours'],
            [2, '2026-05-14', null, null, 'Ascension (excursion)'],
            [2, '2026-05-17', null, null, 'Sakafom-pitiavana'],
            [2, '2026-05-25', null, null, 'Lundi de Pentecôte'],
            [2, '2026-06-05', '2026-06-17', 'du', 'Examens semestriels'],
            [2, '2026-06-11', null, null, 'Réunion des éducateurs avec les responsables des foyers'],
            [2, '2026-06-17', null, null, 'Grand oral'],
            [2, '2026-06-18', null, null, 'Réunion des enseignants'],
            [2, '2026-06-19', null, null, 'Clôture de l’année académique par la Sainte Messe'],
            [2, '2026-09-24', null, null, 'Rentrée 2026-2027'],
        ];

        DB::table('calendrier_evenements')->insert(array_map(fn ($e) => [
            'calendrier_id' => $id, 'semestre' => $e[0], 'date_debut' => $e[1], 'date_fin' => $e[2],
            'liaison' => $e[3], 'libelle' => $e[4], 'created_at' => $maintenant, 'updated_at' => $maintenant,
        ], $evenements));
    }
};
