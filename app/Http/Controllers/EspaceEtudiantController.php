<?php

namespace App\Http\Controllers;

use App\Models\EtudiantParcours;
use App\Services\ReleveService;
use Illuminate\Http\Request;

/**
 * Espace de l'étudiant connecté : il n'accède qu'à SES données,
 * déterminées par son compte (jamais par un identifiant envoyé par le navigateur).
 */
class EspaceEtudiantController extends Controller
{
    public function __construct(private ReleveService $releves)
    {
    }

    /** GET /api/etudiant/parcours : ses inscriptions (années / niveaux), la plus récente d'abord */
    public function parcours(Request $request)
    {
        $etudiant = $this->etudiant($request);

        return EtudiantParcours::with(['niveau:id,niveau', 'annee_academique:id,annee_aca'])
            ->where('id_etudiant', $etudiant->id)
            ->orderByDesc('id_annee_aca')
            ->get()
            ->map(fn ($p) => [
                'id'     => $p->id,
                'annee'  => $p->annee_academique?->annee_aca,
                'niveau' => $p->niveau?->niveau,
                'statut' => $p->statut,
            ]);
    }

    /** GET /api/etudiant/releve?parcours=<id> : relevé de notes (par défaut, l'inscription la plus récente) */
    public function releve(Request $request)
    {
        $etudiant = $this->etudiant($request);

        $parcours = EtudiantParcours::where('id_etudiant', $etudiant->id)
            ->when($request->filled('parcours'), fn ($q) => $q->whereKey($request->integer('parcours')))
            ->orderByDesc('id_annee_aca')
            ->first();

        if (!$parcours) {
            return response()->json(['message' => 'Aucune inscription trouvée pour votre compte.'], 404);
        }

        return response()->json($this->releves->pour($parcours));
    }

    private function etudiant(Request $request)
    {
        $etudiant = $request->user()->etudiants;
        abort_if(!$etudiant, 404, "Aucun dossier étudiant n'est rattaché à ce compte.");

        return $etudiant;
    }
}
