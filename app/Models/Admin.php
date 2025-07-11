<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Admin extends Model
{
    protected $table = 'admins';

    protected $fillable = [
        'id_user_cession',
        'nom_prenom',
        'email',
        'telephone',
    ];

    public function userCession()
    {
        return $this->belongsTo(User::class, 'id_user_cession');
    }
}
