<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Niveau extends Model
{
    protected $fillable = ['niveau'];

    /**
     * Niveau suivant du même cycle (L1 → L2, L3 → null : fin de cycle, l'étudiant est diplômé).
     * Ordre des niveaux : lettre du cycle puis numéro, comme l'affichage (utils/niveaux.js).
     */
    public function suivant(): ?self
    {
        if (!preg_match('/^([A-Za-z]+)\s*(\d+)$/', trim($this->niveau), $m)) {
            return null;
        }

        return static::whereRaw("UPPER(REPLACE(niveau, ' ', '')) = ?", [strtoupper($m[1]) . ((int) $m[2] + 1)])->first();
    }

    public function matieres()
    {
        return $this->hasMany(Matiere::class, 'id_niveau');
    }
}
