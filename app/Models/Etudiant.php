<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Etudiant extends Model
{
    protected $table = 'etudiants';
    protected $fillable = [
        'nom_prenom',
        'id_user_cession',
        'email',
        'telephone',
        'diocese',
        'matricule',
        'date_sortie',
        'motif_sortie',
    ];

    protected $casts = ['date_sortie' => 'date:Y-m-d'];

    /** A quitté le séminaire (diplômé, abandon, transfert, exclusion) */
    public function estSorti(): bool
    {
        return $this->date_sortie !== null;
    }
    public function userCession()
    {
        return $this->belongsTo(User::class, 'id_user_cession');
    }
    public function parcours()
    {
        return $this->hasMany(EtudiantParcours::class, 'id_etudiant');
    }
    
// public function notes()
// {
//     return $this->hasMany(Note::class, 'etudiant_numero', 'numero');
// }

    use HasFactory;
}
