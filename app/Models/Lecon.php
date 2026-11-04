<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Support de cours d'une matière pour une année. Les étudiants ne voient que les leçons publiées.
 * Le droit de modifier suit l'attribution de la matière (programme_matieres), pas l'auteur :
 * le nouvel enseignant d'une matière reprend ses leçons.
 */
class Lecon extends Model
{
    protected $table = 'lecons';

    protected $fillable = ['id_annee_aca', 'id_matiere', 'id_enseignant', 'titre', 'contenu', 'fichier', 'nom_fichier', 'publie_le'];

    protected $casts = ['publie_le' => 'datetime'];

    public function matiere()
    {
        return $this->belongsTo(Matiere::class, 'id_matiere');
    }

    public function enseignant()
    {
        return $this->belongsTo(Enseignant::class, 'id_enseignant');
    }

    public function scopePubliees($query)
    {
        return $query->whereNotNull('publie_le');
    }
}
