<?php

namespace App\Http\Controllers;

use App\Models\Etudiant;
use Illuminate\Http\Request;
use App\Models\EtudiantParcours;

class EtudiantController extends Controller
{
    public function index()
    {
        return Etudiant::orderBy('id')->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nom_prenom' => 'required|string|max:255',
            'diocese' => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'telephone' => 'nullable|string|max:30',
        ]);

        $etudiant = Etudiant::create($validated);

        return response()->json($etudiant, 201);
    }

    public function show($id)
    {
        return Etudiant::findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $etudiant = Etudiant::findOrFail($id);

        $validated = $request->validate([
            'nom_prenom' => 'required|string|max:255',
            'diocese' => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'telephone' => 'nullable|string|max:30',
        ]);

        $etudiant->update($validated);

        return response()->json($etudiant);
    }

    public function destroy($id)
    {
        $etudiant = Etudiant::findOrFail($id);
        $etudiant->delete();

        return response()->json(['message' => 'Étudiant supprimé avec succès.']);
    }

    public function parcoursParAnneeEtNiveau($id_annee, $id_niveau)
    {
        $parcours = EtudiantParcours::with('etudiant')
            ->where('id_annee_aca', $id_annee)
            ->where('id_niveau', $id_niveau)
            ->get();

        // Transformer les données pour retourner les infos utiles
        $result = $parcours->map(function ($p) {
            return [
                'id' => $p->etudiant->id,
                'nom_prenom' => $p->etudiant->nom_prenom,
                'matricule' => $p->etudiant->matricule,
                'diocese' => $p->etudiant->diocese,
                'email' => $p->etudiant->email,
                'telephone' => $p->etudiant->telephone,
            ];
        });

        return response()->json($result);
    }
}
