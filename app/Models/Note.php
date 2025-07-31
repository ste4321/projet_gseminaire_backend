<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Note extends Model
{
    use HasFactory;

    protected $table = 'notes';

    protected $fillable = [
        'id_etudiant_parcours',
        'id_matiere',
        'note',
        'statut',
    ];

    // 🔗 Relation avec EtudiantParcours
    public function etudiantParcours()
    {
        return $this->belongsTo(EtudiantParcours::class, 'id_etudiant_parcours');
    }

    // 🔗 Relation avec Matiere
    public function matiere()
    {
        return $this->belongsTo(Matiere::class, 'id_matiere');
    }
    /**
     * Accessor pour formater la note
     */
    public function getNoteFormattedAttribute()
    {
        return $this->note ? number_format($this->note, 2) : '--';
    }

    /**
     * Scope pour filtrer par étudiant parcours
     */
    public function scopeByEtudiantParcours($query, $etudiantParcoursId)
    {
        return $query->where('id_etudiant_parcours', $etudiantParcoursId);
    }

    /**
     * Scope pour filtrer par matière
     */
    public function scopeByMatiere($query, $matiereId)
    {
        return $query->where('id_matiere', $matiereId);
    }

    /**
     * Scope pour les notes validées
     */
    public function scopeValidated($query)
    {
        return $query->where('statut', 'valide');
    }

    /**
     * Scope pour les notes avec une valeur
     */
    public function scopeWithValue($query)
    {
        return $query->whereNotNull('note')->where('note', '>', 0);
    }
}
