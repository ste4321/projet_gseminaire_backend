<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Calendrier académique d'une année, affiché sur le site vitrine une fois publié */
class Calendrier extends Model
{
    public const TITRES_SEMESTRES = [1 => 'Premier semestre', 2 => 'Deuxième semestre'];

    protected $fillable = ['annee', 'publie', 's1_debut', 's1_fin', 's2_debut', 's2_fin'];

    protected $casts = [
        'publie'   => 'boolean',
        's1_debut' => 'date:Y-m-d',
        's1_fin'   => 'date:Y-m-d',
        's2_debut' => 'date:Y-m-d',
        's2_fin'   => 'date:Y-m-d',
    ];

    public function evenements()
    {
        return $this->hasMany(CalendrierEvenement::class)->orderBy('date_debut')->orderBy('id');
    }

    /** Calendrier affiché sur le site : le plus récent des calendriers publiés */
    public static function affiche(): ?self
    {
        return static::where('publie', true)->orderByDesc('annee')->first();
    }

    /**
     * Forme lue par le site vitrine (FloatingCalendar) : semestres avec leurs bornes
     * (saisies, sinon déduites des événements) et événements { date, end, join, label }.
     */
    public function pourLeSite(): array
    {
        $semestres = [];
        foreach (self::TITRES_SEMESTRES as $numero => $titre) {
            $evenements = $this->evenements->where('semestre', $numero)->values();
            $debut = $this->{"s{$numero}_debut"}?->format('Y-m-d') ?? $evenements->min(fn ($e) => $e->date_debut->format('Y-m-d'));
            $fin = $this->{"s{$numero}_fin"}?->format('Y-m-d') ?? $evenements->max(fn ($e) => ($e->date_fin ?? $e->date_debut)->format('Y-m-d'));
            if ($evenements->isEmpty() && !$debut) {
                continue;
            }
            $semestres[] = [
                'numero' => $numero,
                'titre' => $titre,
                'debut' => $debut,
                'fin' => $fin,
                'evenements' => $evenements->map(fn (CalendrierEvenement $e) => [
                    'id' => $e->id,
                    'date' => $e->date_debut->format('Y-m-d'),
                    'end' => $e->date_fin?->format('Y-m-d'),
                    'join' => $e->date_fin ? $e->liaison : null,
                    'label' => $e->libelle,
                ])->all(),
            ];
        }

        return ['annee' => $this->annee, 'semestres' => $semestres, 'updated_at' => $this->updated_at];
    }
}
