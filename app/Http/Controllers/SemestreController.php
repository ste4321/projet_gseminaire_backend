<?php

namespace App\Http\Controllers;

use App\Models\Matiere;
use App\Models\Semestre;
use Illuminate\Http\Request;

class SemestreController extends Controller
{
    public function index()
    {
        return Semestre::orderBy('id')->get();
    }

    public function store(Request $request)
    {
        return response()->json(Semestre::create($this->validated($request)), 201);
    }

    public function show($id)
    {
        return Semestre::findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $semestre = Semestre::findOrFail($id);
        $semestre->update($this->validated($request));
        return $semestre;
    }

    public function destroy($id)
    {
        $semestre = Semestre::findOrFail($id);

        // La suppression effacerait en cascade les matières du semestre et leurs notes
        $matieres = Matiere::where('id_semestre', $semestre->id)->count();
        if ($matieres) {
            return response()->json([
                'message' => "Impossible de supprimer : {$matieres} matière(s) sont rattachées à ce semestre.",
            ], 409);
        }

        $semestre->delete();
        return response()->json(['message' => 'Semestre supprimé.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'semestre'      => ['required', 'string', 'max:100'],
            'code_semestre' => ['nullable', 'string', 'max:20'],
        ]);
    }
}
