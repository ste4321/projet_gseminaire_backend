<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Etudiant extends Model
{
    protected $table = 'etudiants';
    protected $fillable = ['numero','nom_prenom','diocese','annee','idannee_aca'];

// public function notes()
// {
//     return $this->hasMany(Note::class, 'etudiant_numero', 'numero');
// }

    use HasFactory;
}
