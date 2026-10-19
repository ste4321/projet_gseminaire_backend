<?php

namespace App\Http\Controllers;

use App\Models\Matiere;
use App\Models\Niveau;
use App\Models\Note;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MatiereController extends Controller
{
    public function index()
    {
        return Matiere::with(['niveau', 'semestre'])->orderBy('id')->get();
    }

    /** $niveau : numéro (1 pour L1) ou libellé complet (L1, M1...) */
    public function getMatieresByNiveau($niveau)
    {
        $libelle = ctype_digit((string) $niveau) ? 'L' . $niveau : strtoupper($niveau);
        $niveauRecord = Niveau::where('niveau', $libelle)->first();

        if (!$niveauRecord) {
            return response()->json(['message' => "Le niveau {$libelle} n'existe pas."], 404);
        }

        return Matiere::where('id_niveau', $niveauRecord->id)
            ->with(['niveau', 'semestre'])
            ->orderBy('matiere')
            ->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules(), $this->messages());

        return response()->json(Matiere::create($validated)->load(['niveau', 'semestre']), 201);
    }

    public function show($id)
    {
        return Matiere::with(['niveau', 'semestre'])->findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $matiere = Matiere::findOrFail($id);
        $matiere->update($request->validate($this->rules($matiere), $this->messages()));

        return $matiere->load(['niveau', 'semestre']);
    }

    public function destroy($id)
    {
        $matiere = Matiere::findOrFail($id);

        // La suppression effacerait aussi toutes les notes de la matière (cascade)
        $nbNotes = Note::where('id_matiere', $matiere->id)->count();
        if ($nbNotes > 0) {
            return response()->json([
                'message' => "Impossible de supprimer : {$nbNotes} note(s) sont enregistrées pour cette matière.",
            ], 409);
        }

        $matiere->delete();
        return response()->json(['message' => 'Matière supprimée.']);
    }

    private function rules(?Matiere $matiere = null): array
    {
        $niveau = request('id_niveau');
        $semestre = request('id_semestre');

        return [
            'matiere'      => ['required', 'string', 'max:255'],
            'heures'       => ['required', 'integer', 'min:0', 'max:1000'],
            'id_niveau'    => ['required', 'exists:niveaux,id'],
            'id_semestre'  => ['nullable', 'exists:semestres,id'],
            // Facultatif. Le même code peut servir à plusieurs niveaux ou semestres (THG en L1 et en L2) :
            // l'import des notes le retrouve par code + niveau + semestre (NOTES_THG_L2_S1_2025-2026.xlsx).
            // Il n'est donc unique qu'au sein d'un même niveau et d'un même semestre.
            'code_matiere' => [
                'nullable', 'string', 'max:50',
                Rule::unique('matieres', 'code_matiere')
                    ->where('id_niveau', $niveau)
                    ->where(fn ($q) => $semestre ? $q->where('id_semestre', $semestre) : $q->whereNull('id_semestre'))
                    ->ignore($matiere?->id),
            ],
            'coefficient'  => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    private function messages(): array
    {
        return [
            'code_matiere.unique'   => 'Ce code est déjà utilisé par une autre matière du même niveau et du même semestre.',
        ];
    }
}
