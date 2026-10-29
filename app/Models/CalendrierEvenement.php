<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Événement du calendrier académique : un jour, une période (liaison « du ») ou deux jours (liaison « et »).
 */
class CalendrierEvenement extends Model
{
    public const LIAISONS = ['du', 'et'];

    protected $fillable = ['semestre', 'date_debut', 'date_fin', 'liaison', 'libelle'];

    protected $casts = [
        'semestre'   => 'integer',
        'date_debut' => 'date:Y-m-d',
        'date_fin'   => 'date:Y-m-d',
    ];

    public function calendrier()
    {
        return $this->belongsTo(Calendrier::class);
    }
}
