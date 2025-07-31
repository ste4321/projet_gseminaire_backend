<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Matiere;
use App\Models\Niveau;

class MatiereController extends Controller
{
    public function index()
    {
        try {
            $matieres = Matiere::with(['niveau', 'semestre'])->orderBy('id')->get();;
            return response()->json($matieres, 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erreur lors de la récupération des matières',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getMatieresByNiveau($niveau)
    {
        try {
            // Trouver l'ID du niveau basé sur le niveau passé (ex: 1 pour L1)
            $niveauRecord = Niveau::where('niveau', 'L' . $niveau)->first();
            
            if (!$niveauRecord) {
                return response()->json([
                    'error' => 'Niveau non trouvé',
                    'message' => "Le niveau L{$niveau} n'existe pas"
                ], 404);
            }

            $matieres = Matiere::where('id_niveau', $niveauRecord->id)
                              ->with(['niveau', 'semestre'])
                              ->orderBy('matiere')
                              ->get();
            
            return response()->json($matieres, 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erreur lors de la récupération des matières',
                'message' => $e->getMessage()
            ], 500);
        }
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
