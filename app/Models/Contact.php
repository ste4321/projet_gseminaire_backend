<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Message envoyé depuis le formulaire « Contactez-nous » du site vitrine */
class Contact extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'email', 'message', 'lu_le', 'repondu_le'];

    protected $casts = [
        'lu_le'      => 'datetime',
        'repondu_le' => 'datetime',
    ];
}
