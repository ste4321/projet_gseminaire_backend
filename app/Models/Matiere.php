<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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

}
