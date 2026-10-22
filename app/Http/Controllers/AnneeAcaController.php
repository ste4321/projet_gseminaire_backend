<?php

namespace App\Http\Controllers;

use App\Models\AnneeAca;
use App\Models\ProgrammeMatiere;
use Illuminate\Http\Request;

class AnneeAcaController extends Controller
{
    public function index()
    {
        return AnneeAca::orderByDesc('id')->get();
    }

    /**
     * POST /api/annee_aca : nouvelle année académique (« 2026-2027 »), sans attendre l'import des étudiants.
     * Elle reprend le programme de l'année précédente (voir AnneeAca::booted) et devient l'année par défaut :
     * les étudiants ajoutés à la main y sont inscrits.
     */
    public function store(Request $request)
    {
        $request->merge(['annee_aca' => preg_replace('/\s+/', '', (string) $request->input('annee_aca'))]);
        $validated = $request->validate([
            'annee_aca' => [
                // bail : la vérification « l'année suit » ne s'exécute que sur un format valide
                'bail', 'required', 'regex:/^[0-9]{4}-[0-9]{4}$/', 'unique:annee_aca,annee_aca',
                function ($attribut, $valeur, $echec) {
                    [$debut, $fin] = array_map('intval', explode('-', $valeur));
                    if ($fin !== $debut + 1) {
                        $echec('La deuxième année doit suivre la première (ex. 2026-2027).');
                    }
                },
            ],
        ], [
            'annee_aca.required' => "Indiquez l'année académique.",
            'annee_aca.regex'    => 'Format attendu : 2026-2027.',
            'annee_aca.unique'   => 'Cette année académique existe déjà.',
        ]);

        $annee = AnneeAca::create($validated);
        $reprises = ProgrammeMatiere::where('id_annee_aca', $annee->id)->count();

        return response()->json([
            'message' => "Année {$annee->annee_aca} créée."
                . ($reprises > 0 ? " {$reprises} matière(s) reprise(s) du programme précédent." : ' Son programme est vide : ajoutez-y les matières.'),
            'annee'   => $annee,
            'matieres_reprises' => $reprises,
        ], 201);
    }
}
