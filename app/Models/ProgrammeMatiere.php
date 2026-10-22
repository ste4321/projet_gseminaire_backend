<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Une matière au programme d'une année académique, avec le coefficient et les heures de cette année-là.
 */
class ProgrammeMatiere extends Model
{
    protected $table = 'programme_matieres';

    protected $fillable = ['id_annee_aca', 'id_matiere', 'coefficient', 'heures'];

    protected $casts = [
        'coefficient' => 'float',
        'heures'      => 'integer',
    ];

    public function annee()
    {
        return $this->belongsTo(AnneeAca::class, 'id_annee_aca');
    }

    public function matiere()
    {
        return $this->belongsTo(Matiere::class, 'id_matiere');
    }

    /**
     * Reprend dans l'année $vers le programme de l'année $depuis (un seul niveau si $niveauId est donné).
     * Les matières déjà au programme de $vers ne sont pas touchées. Renvoie le nombre de matières ajoutées.
     */
    public static function copier(int $depuis, int $vers, ?int $niveauId = null): int
    {
        $deja = static::where('id_annee_aca', $vers)->pluck('id_matiere');

        $lignes = static::where('id_annee_aca', $depuis)
            ->whereNotIn('id_matiere', $deja)
            ->when($niveauId, fn ($q) => $q->whereHas('matiere', fn ($m) => $m->where('id_niveau', $niveauId)))
            ->get();

        foreach ($lignes as $ligne) {
            static::create([
                'id_annee_aca' => $vers,
                'id_matiere'   => $ligne->id_matiere,
                'coefficient'  => $ligne->coefficient,
                'heures'       => $ligne->heures,
            ]);
        }

        return $lignes->count();
    }
}
