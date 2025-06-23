<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Edt extends Model
{
    use HasFactory;

    protected $fillable = ['image_url', 'semestre','updated_by'];

}
