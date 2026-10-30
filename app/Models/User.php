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
        'derniere_connexion_at' => 'datetime',
        'derniere_activite_at' => 'datetime',
        'provisoire_depuis' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Âge du mot de passe provisoire : daté à chaque nouveau mot de passe provisoire (création,
        // réinitialisation), effacé quand la personne choisit le sien
        static::saving(function (User $user) {
            if ($user->must_change_password && ($user->isDirty('must_change_password') || $user->isDirty('password') || !$user->provisoire_depuis)) {
                $user->provisoire_depuis = now();
            } elseif (!$user->must_change_password) {
                $user->provisoire_depuis = null;
            }
        });
    }

    /** Signal de présence au-delà duquel un compte n'est plus « en ligne » (l'application l'envoie toutes les 45 s) */
    public const PRESENCE_SECONDES = 120;

    /** Connexion réussie de la personne elle-même ; ne modifie pas updated_at */
    public function noterConnexion(): void
    {
        $this->timestamps = false;
        $this->forceFill(['derniere_connexion_at' => now(), 'derniere_activite_at' => now()])->save();
        $this->timestamps = true;
        $this->diffuserPresence(true);
    }

    /** Application ouverte (signal de présence) ; écrit au plus une fois toutes les 20 s, sans modifier updated_at */
    public function noterActivite(): void
    {
        if ($this->derniere_activite_at && $this->derniere_activite_at->gt(now()->subSeconds(20))) {
            return;
        }
        $this->timestamps = false;
        $this->forceFill(['derniere_activite_at' => now()])->save();
        $this->timestamps = true;
        $this->diffuserPresence(true);
    }

    /**
     * Statut en direct pour la page « Comptes et accès » (canal privé « comptes », administrateurs).
     * Serveur Reverb arrêté : sans conséquence ni journal (envoyé à chaque signal de présence),
     * la page se rabat sur une relecture toutes les 30 s.
     */
    public function diffuserPresence(bool $enLigne): void
    {
        try {
            broadcast(new \App\Events\PresenceCompte($this, $enLigne));
        } catch (\Throwable $e) {
            // rien : voir ci-dessus
        }
    }

    /**
     * Comptes en ligne : signal de présence récent et session ouverte (une déconnexion supprime la session,
     * le statut tombe aussitôt). Les sessions de consultation par l'administration ne comptent pas.
     */
    public function scopeAvecPresence($query)
    {
        return $query->withExists(['tokens as session_ouverte' => fn ($q) => $q
            ->where('name', 'auth_token')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())),
        ]);
    }

    public function estEnLigne(): bool
    {
        return (bool) $this->session_ouverte
            && $this->derniere_activite_at
            && $this->derniere_activite_at->gt(now()->subSeconds(self::PRESENCE_SECONDES));
    }
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
