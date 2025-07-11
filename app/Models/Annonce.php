<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Annonce extends Model
{
    protected $fillable = ['titre','expediteur','description','fichier','id_annee_aca','id_niveau'];

    public function niveau(){ return $this->belongsTo(Niveau::class,'id_niveau'); }
    public function anneeAca(){ return $this->belongsTo(AnneeAca::class,'id_annee_aca'); }


}
