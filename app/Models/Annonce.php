<?php

namespace App\Models;

use App\Services\DiffusionService;
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
        $ids = $this->pourEtudiants() ? DiffusionService::etudiantsInscrits($this->id_annee_aca, $this->id_niveau) : [];

        if ($this->pourEnseignants()) {
            $ids = array_merge($ids, DiffusionService::actifs(Enseignant::whereNotNull('id_user_cession')->pluck('id_user_cession')->all()));
        }

        return array_values(array_unique($ids));
    }
}
