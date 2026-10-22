<?php

namespace App\Http\Controllers;

use App\Models\AnneeAca;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\ProgrammeMatiere;
use Illuminate\Http\Request;

/**
 * Programme d'une année académique : quelles matières du catalogue y sont enseignées.
 * Ajouter ou retirer une matière ici ne touche que cette année ; les notes des autres années restent intactes.
 */
class ProgrammeController extends Controller
{
    /** POST /api/programme : met une matière du catalogue au programme d'une année */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'id_annee_aca' => 'required|integer|exists:annee_aca,id',
            'id_matiere'   => 'required|integer|exists:matieres,id',
            'coefficient'  => 'nullable|numeric|min:0|max:100',
            'heures'       => 'nullable|integer|min:0|max:1000',
        ]);

        $matiere = Matiere::findOrFail($validated['id_matiere']);
        $ligne = ProgrammeMatiere::firstOrCreate(
            ['id_annee_aca' => $validated['id_annee_aca'], 'id_matiere' => $matiere->id],
            [
                // Sans précision : les valeurs de la dernière année où elle a été enseignée, sinon celles du catalogue
                'coefficient' => $validated['coefficient'] ?? $this->dernier($matiere, 'coefficient', (float) str_replace(',', '.', (string) $matiere->coefficient)),
                'heures'      => $validated['heures'] ?? $this->dernier($matiere, 'heures', (int) $matiere->heures),
            ]
        );

        return response()->json([
            'message'   => "{$matiere->matiere} ajoutée au programme.",
            'programme' => $ligne,
        ], $ligne->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * DELETE /api/programme : retire une matière du programme d'une année.
     * Les notes déjà saisies cette année sont conservées : elles ne comptent plus dans les relevés
     * (qui ne lisent que le programme) et reviennent telles quelles si la matière est remise au programme.
     */
    public function destroy(Request $request)
    {
        $validated = $request->validate([
            'id_annee_aca' => 'required|integer|exists:annee_aca,id',
            'id_matiere'   => 'required|integer|exists:matieres,id',
        ]);

        $notes = Note::where('id_matiere', $validated['id_matiere'])
            ->whereHas('etudiantParcours', fn ($p) => $p->where('id_annee_aca', $validated['id_annee_aca']))
            ->count();

        ProgrammeMatiere::where($validated)->delete();

        return response()->json([
            'message' => 'Matière retirée du programme de cette année. Les autres années ne changent pas.'
                . ($notes > 0 ? " Ses {$notes} note(s) de cette année sont conservées et reviendront si vous la remettez au programme." : ''),
            'notes_conservees' => $notes,
        ]);
    }

    /** POST /api/programme/copier : reprend le programme d'une autre année (un niveau, ou tous) */
    public function copier(Request $request)
    {
        $validated = $request->validate([
            'id_annee_aca' => 'required|integer|exists:annee_aca,id',
            'depuis'       => 'required|integer|different:id_annee_aca|exists:annee_aca,id',
            'id_niveau'    => 'nullable|integer|exists:niveaux,id',
        ], [
            'depuis.different' => "Choisissez une autre année que celle du programme.",
        ]);

        $ajoutees = ProgrammeMatiere::copier($validated['depuis'], $validated['id_annee_aca'], $validated['id_niveau'] ?? null);
        $source = AnneeAca::find($validated['depuis'])->annee_aca;

        return response()->json([
            'message'  => $ajoutees > 0
                ? "{$ajoutees} matière(s) reprise(s) du programme {$source}."
                : "Rien à reprendre : le programme {$source} ne contient pas d'autre matière pour ce niveau.",
            'ajoutees' => $ajoutees,
        ]);
    }

    private function dernier(Matiere $matiere, string $champ, $defaut)
    {
        return $matiere->programmes()->orderByDesc('id_annee_aca')->value($champ) ?? $defaut;
    }
}
