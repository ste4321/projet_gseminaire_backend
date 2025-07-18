<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EtudiantParcours extends Model
{
    use HasFactory;

    protected $table = 'etudiant_parcours';

    protected $fillable = [
        'id_etudiant',
        'id_annee_aca',
        'id_niveau',
        'statut',
        'date_inscription',
        'matricule',
    ];

    /**
     * Relations
     */

    public function etudiant()
    {
        return $this->belongsTo(Etudiant::class, 'id_etudiant');
    }

    public function annee_academique()
    {
        return $this->belongsTo(AnneeAca::class, 'id_annee_aca');
    }

    public function niveau()
    {
        return $this->belongsTo(Niveau::class, 'id_niveau');
    }
}
