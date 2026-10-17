<?php

namespace App\Services;

use App\Models\EtudiantParcours;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\PublicationNote;

/**
 * Relevé de notes d'une inscription (un étudiant, une année, un niveau).
 * Source unique des calculs : utilisé par l'espace étudiant et par l'administration (bulletin PDF).
 *
 * Moyenne = Σ(note × coefficient) / Σ(coefficients des matières notées).
 * Une note de 0 compte ; une matière sans note n'entre pas dans la moyenne.
 *
 * Statut (semestre et général), seulement quand toutes les notes sont saisies :
 * « Validé » si la moyenne atteint SEUIL_VALIDATION, sinon « Rattrapage ».
 *
 * Vue étudiant ($publiesSeulement) : les notes d'un semestre non publié ne quittent pas le serveur.
 */
class ReleveService
{
    public const SEUIL_VALIDATION = 10;
    public const VALIDE = 'Validé';
    public const RATTRAPAGE = 'Rattrapage';

    public function pour(EtudiantParcours $parcours, bool $publiesSeulement = false): array
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

        $publications = PublicationNote::where('id_annee_aca', $parcours->id_annee_aca)
            ->where('id_niveau', $parcours->id_niveau)
            ->pluck('created_at', 'id_semestre');

        $semestres = $matieres
            ->groupBy(fn ($m) => $m->id_semestre ?? 0)
            ->map(function ($liste) use ($notes, $publications, $publiesSeulement) {
                $semestre = $liste->first()->semestre;
                $publie = $semestre && $publications->has($semestre->id);
                $visible = $publie || !$publiesSeulement;

                $lignes = $liste->map(fn ($m) => [
                    'id'          => $m->id,
                    'matiere'     => $m->matiere,
                    'code'        => $m->code_matiere,
                    'coefficient' => (float) $m->coefficient,
                    'heures'      => (int) $m->heures,
                    'note'        => $visible && $notes->has($m->id) ? (float) $notes[$m->id] : null,
                ])->values();

                return [
                    'id'        => $semestre?->id,
                    'semestre'  => $semestre?->semestre ?? 'Hors semestre',
                    'code'      => $semestre?->code_semestre,
                    'publie'    => $publie,
                    'publie_le' => $publie ? $publications[$semestre->id]?->toIso8601String() : null,
                    'matieres'  => $lignes,
                ] + $this->resultat($lignes->all());
            })
            ->values();

        // Pour l'étudiant, le résultat général ne porte que sur les semestres publiés
        $comptes = $publiesSeulement ? $semestres->where('publie', true) : $semestres;
        $toutes = $comptes->flatMap(fn ($s) => $s['matieres'])->all();
        $general = $this->resultat($toutes);
        // Statut général : seulement quand tous les semestres sont pris en compte
        if ($comptes->count() !== $semestres->count()) {
            $general['statut'] = null;
        }

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
            'general'   => $general + [
                'matieres_total'  => $matieres->count(),
                'notes_saisies'   => count(array_filter($toutes, fn ($l) => $l['note'] !== null)),
                'semestres_total' => $semestres->count(),
                'semestres_publies' => $semestres->where('publie', true)->count(),
            ],
            'seuil_validation' => self::SEUIL_VALIDATION,
        ];
    }

    /** @param array<int, array{note: ?float, coefficient: float}> $lignes */
    private function resultat(array $lignes): array
    {
        $notees = array_filter($lignes, fn ($l) => $l['note'] !== null);
        $points = array_sum(array_map(fn ($l) => $l['note'] * $l['coefficient'], $notees));
        $coefficients = array_sum(array_map(fn ($l) => $l['coefficient'], $notees));
        $moyenne = $coefficients > 0 ? round($points / $coefficients, 2) : null;
        $complet = count($lignes) > 0 && count($notees) === count($lignes);

        return [
            'moyenne'      => $moyenne,
            'points'       => round($points, 2),
            'coefficients' => $coefficients,
            'statut'       => $complet ? ($moyenne >= self::SEUIL_VALIDATION ? self::VALIDE : self::RATTRAPAGE) : null,
        ];
    }
}
