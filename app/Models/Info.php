<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Info extends Model
{
    protected $fillable = ['auteur', 'titre', 'description'];

    use HasFactory;
}
