<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AnneeAca extends Model
{
    protected $table = 'annee_aca';
    protected $fillable = ['annee_aca'];
    public function parcours()
    {
        return $this->hasMany(EtudiantParcours::class, 'id_annee_aca');
    }
}
