<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Password extends Model
{
    use HasFactory;
    protected $fillable = [
        'id_user_cession',
        'password',
        'psw_scrypt',
    ];
}
