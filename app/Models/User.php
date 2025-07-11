<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $table = 'user_cession';

    protected $fillable = [
        'email',
        'password',
        'is_verified',
        'confirmation_code', 
        'role',
    ];
    protected $casts = [
        'is_verified' => 'boolean',
    ];
    public function hasLinkedProfile()
    {
        return $this->admin()->exists() || $this->enseignant()->exists() || $this->etudiants()->exists();
    }

    public function admin()
    {
        return $this->hasOne(Admin::class, 'id_user_cession');
    }
    public function enseignant()
    {
        return $this->hasOne(Enseignant::class, 'id_user_cession');
    }
    public function etudiants()
    {
        return $this->hasOne(Etudiant::class, 'id_user_cession');
    }
    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    // protected $casts = [
    //     'email_verified_at' => 'datetime',
    // ];
}
