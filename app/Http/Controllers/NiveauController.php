<?php

namespace App\Http\Controllers;

use App\Models\AnneeAca;
use App\Models\EtudiantParcours;
use App\Models\Matiere;
use App\Models\Niveau;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NiveauController extends Controller
{
    /** Niveaux, avec le nombre de matières et d'inscrits de l'année en cours */
    public function index()
    {
        $courante = AnneeAca::courante()?->id;

        return Niveau::withCount([
            'matieres',
            'parcours as inscrits_count' => fn ($q) => $q->where('id_annee_aca', $courante),
        ])->orderBy('niveau')->get();
    }

    public function store(Request $request)
    {
        return response()->json(Niveau::create($this->validated($request)), 201);
    }

    public function show($id)
    {
        return Niveau::findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $niveau = Niveau::findOrFail($id);
        $niveau->update($this->validated($request, $niveau));
        return $niveau;
    }

    public function destroy($id)
    {
        $niveau = Niveau::findOrFail($id);

        // La suppression effacerait en cascade matières, inscriptions, notes et annonces
        $inscrits = EtudiantParcours::where('id_niveau', $niveau->id)->count();
        $matieres = Matiere::where('id_niveau', $niveau->id)->count();
        if ($inscrits || $matieres) {
            return response()->json([
                'message' => "Impossible de supprimer {$niveau->niveau} : {$inscrits} inscription(s) et {$matieres} matière(s) y sont rattachées.",
            ], 409);
        }

        $niveau->delete();
        return response()->json(['message' => 'Niveau supprimé.']);
    }

    private function validated(Request $request, ?Niveau $niveau = null): array
    {
        $request->merge(['niveau' => strtoupper(trim((string) $request->input('niveau')))]);

        return $request->validate([
            'niveau' => ['required', 'string', 'max:20', Rule::unique('niveaux', 'niveau')->ignore($niveau?->id)],
        ], ['niveau.unique' => 'Ce niveau existe déjà.']);
    }
}
