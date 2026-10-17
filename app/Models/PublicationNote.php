<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Semestre dont les notes sont visibles par les étudiants d'une année et d'un niveau */
class PublicationNote extends Model
{
    protected $table = 'publications_notes';

    protected $fillable = ['id_annee_aca', 'id_niveau', 'id_semestre', 'publie_par'];

    public function auteur()
    {
        return $this->belongsTo(User::class, 'publie_par');
    }
}
