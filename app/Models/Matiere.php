<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Matière du catalogue (nom, code, niveau, semestre).
 * Le coefficient et les heures enregistrés ici sont les valeurs proposées par défaut :
 * ceux qui comptent pour une année sont ceux de son programme (ProgrammeMatiere).
 */
class Matiere extends Model
{
    protected $fillable = [
        'matiere', 'heures', 'id_niveau', 'code_matiere', 'coefficient',  'id_semestre'
    ];

    public function niveau()
    {
        return $this->belongsTo(Niveau::class, 'id_niveau');
    }
    public function semestre()
    {
        return $this->belongsTo(Semestre::class, 'id_semestre');
    }
    public function notes()
    {
        return $this->hasMany(Note::class, 'id_matiere');
    }
    public function programmes()
    {
        return $this->hasMany(ProgrammeMatiere::class, 'id_matiere');
    }

    /**
     * Matières au programme de l'année $anneeId, avec le coefficient et les heures de cette année
     * à la place des valeurs par défaut du catalogue.
     */
    public function scopeAuProgramme($query, int $anneeId)
    {
        return $query
            ->join('programme_matieres as pm', function ($join) use ($anneeId) {
                $join->on('pm.id_matiere', '=', 'matieres.id')->where('pm.id_annee_aca', $anneeId);
            })
            ->select([
                'matieres.id', 'matieres.matiere', 'matieres.code_matiere', 'matieres.id_niveau', 'matieres.id_semestre',
                'matieres.created_at', 'matieres.updated_at', 'pm.coefficient', 'pm.heures',
            ]);
    }
}
