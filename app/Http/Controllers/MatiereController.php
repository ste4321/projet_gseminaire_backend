<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Matiere;

class MatiereController extends Controller
{
    public function index()
    {
        return Matiere::with('niveau', 'semestre')->orderBy('id')->get();
    }

    public function store(Request $request)
    {
        $request->validate([
            'matiere' => 'required',
            'heures' => 'required|integer',
            'id_niveau' => 'required|exists:niveaux,id',
            'id_semestre' => 'nullable|exists:semestres,id',
            'code_matiere' => 'nullable|string',
            'coefficient' => 'nullable|integer'
        ]);

        return Matiere::create($request->all());
    }

    public function show($id)
    {
        return Matiere::with('niveau', 'semestre')->findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $matiere = Matiere::findOrFail($id);
        $matiere->update($request->all());

        return $matiere;
    }

    public function destroy($id)
    {
        Matiere::findOrFail($id)->delete();
        return response()->json(['message' => 'Supprimé']);
    }
}
