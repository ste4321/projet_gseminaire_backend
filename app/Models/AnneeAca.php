<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AnneeAca extends Model
{
    protected $table = 'annee_aca';
    protected $fillable = ['annee_aca'];

    protected static function booted(): void
    {
        // Nouvelle année (ex. créée par l'import des étudiants) : elle reprend le programme de l'année précédente,
        // que l'administration ajuste ensuite dans le module Matières
        static::created(function (AnneeAca $annee) {
            $precedente = static::where('id', '<', $annee->id)->orderByDesc('id')->value('id');
            if ($precedente) {
                ProgrammeMatiere::copier($precedente, $annee->id);
            }
        });
    }

    public function parcours()
    {
        return $this->hasMany(EtudiantParcours::class, 'id_annee_aca');
    }

    public function programme()
    {
        return $this->hasMany(ProgrammeMatiere::class, 'id_annee_aca');
    }
}
