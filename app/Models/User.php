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
        'role',
        'must_change_password',
        'password_changed_at',
    ];
    protected $casts = [
        'is_verified' => 'boolean',
        'must_change_password' => 'boolean',
        'password_changed_at' => 'datetime',
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

    /**
     * Données de session renvoyées au frontend (connexion, /me, consultation) — jamais le mot de passe.
     */
    public function payloadSession(): array
    {
        $profil = match ($this->role) {
            'admin'    => Admin::where('id_user_cession', $this->id)->first(),
            'prof'     => Enseignant::where('id_user_cession', $this->id)->first(),
            'etudiant' => Etudiant::where('id_user_cession', $this->id)->first(),
            default    => null,
        };

        return [
            'id'          => $this->id,
            'email'       => $this->email,
            'role'        => $this->role,
            'nom_prenom'  => $profil?->nom_prenom,
            'profil_id'   => $profil?->id,
            'is_verified' => (bool) $this->is_verified,
            'must_change_password' => (bool) $this->must_change_password,
        ];
    }
}
