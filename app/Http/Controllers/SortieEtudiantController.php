<?php

namespace App\Http\Controllers;

use App\Models\Etudiant;
use App\Models\EtudiantParcours;
use App\Services\SortieService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Départ d'un étudiant en cours d'année (ou annulation), voir SortieService */
class SortieEtudiantController extends Controller
{
    public function __construct(private SortieService $sorties)
    {
    }

    /** POST /api/etudiants/{id}/sortie */
    public function store(Request $request, $id)
    {
        $etudiant = Etudiant::findOrFail($id);
        $donnees = $request->validate([
            'statut' => ['required', Rule::in([...EtudiantParcours::SORTIES, EtudiantParcours::DIPLOME])],
            'date'   => 'nullable|date|before_or_equal:today',
            'motif'  => 'nullable|string|max:500',
        ], [
            'statut.required'      => 'Précisez la raison du départ.',
            'date.before_or_equal' => 'La date de sortie ne peut pas être dans le futur.',
        ]);

        $this->sorties->declarer($etudiant, $donnees['statut'], $donnees['date'] ?? null, $donnees['motif'] ?? null);

        return response()->json([
            'message'  => "Sortie de {$etudiant->nom_prenom} enregistrée."
                . ($donnees['statut'] === EtudiantParcours::DIPLOME ? '' : ' Son compte est désactivé.'),
            'etudiant' => collect($etudiant->fresh()->toArray())->only(['id', 'date_sortie', 'motif_sortie']),
        ]);
    }

    /** DELETE /api/etudiants/{id}/sortie : l'étudiant revient (ou sortie déclarée par erreur) */
    public function destroy($id)
    {
        $etudiant = Etudiant::findOrFail($id);
        $this->sorties->annuler($etudiant);

        return response()->json([
            'message'  => "{$etudiant->nom_prenom} fait de nouveau partie des étudiants. Son compte est réactivé.",
            'etudiant' => collect($etudiant->fresh()->toArray())->only(['id', 'date_sortie', 'motif_sortie']),
        ]);
    }
}
