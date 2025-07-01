<?php

namespace App\Http\Controllers;

use App\Models\Etudiant;
use Illuminate\Http\Request;

class EtudiantController extends Controller
{
    public function index(Request $request)
    {
        $query = Etudiant::query();

        if ($request->has('idannee_aca')) {
            $query->where('idannee_aca', $request->input('idannee_aca'));
        }
        if ($request->has('annee')) {
            $query->where('annee', $request->input('annee'));
        }
        
        return $query->orderBy('numero')->get();
    }

    public function show($numero)
    {
        // Charger les notes si tu as une relation notes()
        return Etudiant::with('notes')->where('numero', $numero)->firstOrFail();
    }
}
