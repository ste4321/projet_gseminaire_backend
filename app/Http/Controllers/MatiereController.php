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
        return [
            'matiere'      => ['required', 'string', 'max:255'],
            'heures'       => ['required', 'integer', 'min:0', 'max:1000'],
            'id_niveau'    => ['required', 'exists:niveaux,id'],
            'id_semestre'  => ['nullable', 'exists:semestres,id'],
            // Le code sert à l'import des notes (NOTES_<CODE>_L1_2025-2026.xlsx)
            'code_matiere' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9]+$/', Rule::unique('matieres', 'code_matiere')->ignore($matiere?->id)],
            'coefficient'  => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    private function messages(): array
    {
        return [
            'code_matiere.required' => 'Le code matière est obligatoire.',
            'code_matiere.unique'   => 'Ce code matière existe déjà.',
            'code_matiere.regex'    => 'Le code matière ne doit contenir que des lettres et des chiffres (sans espace).',
        ];
    }
}
