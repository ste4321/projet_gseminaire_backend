<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Enseignant extends Model
{
    protected $table = 'corps_enseignant';
    protected $fillable = [
        'nom_prenom', 
        'adresse', 
        'email', 
        'telephone',
        'id_user_cession',
    ];
    public function userCession()
    {
        return $this->belongsTo(User::class, 'id_user_cession');
    }
    use HasFactory;

}
