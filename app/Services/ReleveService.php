<?php

namespace App\Services;

use App\Models\EtudiantParcours;
use App\Models\Matiere;
use App\Models\Note;

/**
 * Relevé de notes d'une inscription (un étudiant, une année, un niveau).
 * Source unique des calculs : utilisé par l'espace étudiant et par l'administration (bulletin PDF).
 *
 * Moyenne = Σ(note × coefficient) / Σ(coefficients des matières notées).
 * Une note de 0 compte ; une matière sans note n'entre pas dans la moyenne.
 */
class ReleveService
{
    public function pour(EtudiantParcours $parcours): array
    {
        $parcours->loadMissing(['etudiant', 'niveau', 'annee_academique']);

        $matieres = Matiere::with('semestre')
            ->where('id_niveau', $parcours->id_niveau)
            ->orderBy('id_semestre')
            ->orderBy('matiere')
            ->get();

        $notes = Note::where('id_etudiant_parcours', $parcours->id)
            ->whereNotNull('note')
            ->pluck('note', 'id_matiere');

        $semestres = $matieres
            ->groupBy(fn ($m) => $m->id_semestre ?? 0)
            ->map(function ($liste) use ($notes) {
                $semestre = $liste->first()->semestre;
                $lignes = $liste->map(fn ($m) => [
                    'id'          => $m->id,
                    'matiere'     => $m->matiere,
                    'code'        => $m->code_matiere,
                    'coefficient' => (float) $m->coefficient,
                    'heures'      => (int) $m->heures,
                    'note'        => $notes->has($m->id) ? (float) $notes[$m->id] : null,
                ])->values();

                return [
                    'id'       => $semestre?->id,
                    'semestre' => $semestre?->semestre ?? 'Hors semestre',
                    'code'     => $semestre?->code_semestre,
                    'matieres' => $lignes,
                ] + $this->moyenne($lignes->all());
            })
            ->values();

        $toutes = $semestres->flatMap(fn ($s) => $s['matieres'])->all();

        return [
            'parcours_id' => $parcours->id,
            'etudiant' => [
                'id'         => $parcours->etudiant->id,
                'nom_prenom' => $parcours->etudiant->nom_prenom,
                'matricule'  => $parcours->etudiant->matricule,
                'diocese'    => $parcours->etudiant->diocese,
            ],
            'annee'     => $parcours->annee_academique?->annee_aca,
            'niveau'    => $parcours->niveau?->niveau,
            'semestres' => $semestres,
            'general'   => $this->moyenne($toutes) + [
                'matieres_total' => count($toutes),
                'notes_saisies'  => count(array_filter($toutes, fn ($l) => $l['note'] !== null)),
            ],
        ];
    }

    /** @param array<int, array{note: ?float, coefficient: float}> $lignes */
    private function moyenne(array $lignes): array
    {
        $notees = array_filter($lignes, fn ($l) => $l['note'] !== null);
        $points = array_sum(array_map(fn ($l) => $l['note'] * $l['coefficient'], $notees));
        $coefficients = array_sum(array_map(fn ($l) => $l['coefficient'], $notees));

        return [
            'moyenne'      => $coefficients > 0 ? round($points / $coefficients, 2) : null,
            'points'       => round($points, 2),
            'coefficients' => $coefficients,
        ];
    }
}
