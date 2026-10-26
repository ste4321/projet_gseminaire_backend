<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Annonce extends Model
{
    // Destinataires possibles (voir la migration destinataires_annonces)
    public const ETUDIANTS = 'etudiants';
    public const ENSEIGNANTS = 'enseignants';
    public const TOUS = 'tous';
    public const DESTINATAIRES = [self::ETUDIANTS, self::ENSEIGNANTS, self::TOUS];

    protected $fillable = ['titre','expediteur','description','fichier','destinataires','id_annee_aca','id_niveau'];

    protected $attributes = ['destinataires' => self::ETUDIANTS];

    public function niveau(){ return $this->belongsTo(Niveau::class,'id_niveau'); }
    public function anneeAca(){ return $this->belongsTo(AnneeAca::class,'id_annee_aca'); }

    public function pourEtudiants(): bool
    {
        return in_array($this->destinataires, [self::ETUDIANTS, self::TOUS], true);
    }

    public function pourEnseignants(): bool
    {
        return in_array($this->destinataires, [self::ENSEIGNANTS, self::TOUS], true);
    }

    /** Comptes utilisateurs actifs concernés par l'annonce (ceux à prévenir à sa publication) */
    public function idsUtilisateursConcernes(): array
    {
        $ids = collect();

        if ($this->pourEtudiants()) {
            $ids = $ids->merge(
                Etudiant::query()
                    ->whereNotNull('id_user_cession')
                    ->whereHas('parcours', fn ($q) => $q
                        ->where('id_annee_aca', $this->id_annee_aca)
                        ->when($this->id_niveau, fn ($q) => $q->where('id_niveau', $this->id_niveau)))
                    ->pluck('id_user_cession')
            );
        }

        if ($this->pourEnseignants()) {
            $ids = $ids->merge(Enseignant::whereNotNull('id_user_cession')->pluck('id_user_cession'));
        }

        // Comptes désactivés : ils n'ont pas accès à leur espace, inutile de les prévenir
        return User::whereIn('id', $ids->unique())->where('is_verified', true)->pluck('id')->all();
    }
}
