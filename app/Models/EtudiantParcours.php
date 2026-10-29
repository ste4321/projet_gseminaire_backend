<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EtudiantParcours extends Model
{
    use HasFactory;

    protected $table = 'etudiant_parcours';

    // Issue de l'année (statut de l'inscription)
    public const INSCRIT = 'inscrit';       // année en cours
    public const ADMIS = 'admis';           // passé au niveau suivant
    public const REDOUBLANT = 'redoublant'; // refait le même niveau l'année suivante
    public const DIPLOME = 'diplome';       // cycle terminé
    public const ABANDON = 'abandon';
    public const TRANSFERE = 'transfere';
    public const EXCLU = 'exclu';

    public const STATUTS = [self::INSCRIT, self::ADMIS, self::REDOUBLANT, self::DIPLOME, self::ABANDON, self::TRANSFERE, self::EXCLU];
    /** Départs : l'étudiant quitte le séminaire, son compte est désactivé */
    public const SORTIES = [self::ABANDON, self::TRANSFERE, self::EXCLU];

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
    public function notes()
    {
        return $this->hasMany(Note::class, 'id_etudiant_parcours');
    }

}
