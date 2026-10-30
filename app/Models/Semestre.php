<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Semestre extends Model
{
    protected $fillable = ['semestre', 'code_semestre'];

    public function matieres()
    {
        return $this->hasMany(Matiere::class, 'id_semestre');
    }
}
