<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Niveau extends Model
{
    protected $fillable = ['niveau'];

    public function matieres()
    {
        return $this->hasMany(Matiere::class, 'id_niveau');
    }
}
