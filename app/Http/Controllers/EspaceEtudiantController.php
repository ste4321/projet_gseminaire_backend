<?php

namespace App\Http\Controllers;

use App\Models\EtudiantParcours;
use App\Models\Lecon;
use App\Models\Matiere;
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
        $parcours = $this->parcoursDemande($request);

        if (!$parcours) {
            return response()->json(['message' => 'Aucune inscription trouvée pour votre compte.'], 404);
        }

        // Seuls les semestres publiés par l'administration sont dévoilés
        return response()->json($this->releves->pour($parcours, publiesSeulement: true));
    }

    /** GET /api/etudiant/lecons?parcours=<id> : leçons publiées des matières au programme de son année et de son niveau */
    public function lecons(Request $request)
    {
        $parcours = $this->parcoursDemande($request);

        if (!$parcours) {
            return response()->json(['message' => 'Aucune inscription trouvée pour votre compte.'], 404);
        }

        $matieres = Matiere::auProgramme($parcours->id_annee_aca)
            ->where('matieres.id_niveau', $parcours->id_niveau)
            ->pluck('matieres.id');

        return Lecon::publiees()
            ->with(['matiere:id,matiere,code_matiere', 'enseignant:id,nom_prenom'])
            ->where('id_annee_aca', $parcours->id_annee_aca)
            ->whereIn('id_matiere', $matieres)
            ->orderByDesc('publie_le')
            ->get()
            ->map(fn (Lecon $l) => [
                'id'          => $l->id,
                'titre'       => $l->titre,
                'contenu'     => $l->contenu,
                'fichier'     => $l->fichier,
                'nom_fichier' => $l->nom_fichier,
                'publie_le'   => $l->publie_le,
                'matiere'     => $l->matiere?->matiere,
                'enseignant'  => $l->enseignant?->nom_prenom,
            ]);
    }

    private function parcoursDemande(Request $request): ?EtudiantParcours
    {
        return EtudiantParcours::where('id_etudiant', $this->etudiant($request)->id)
            ->when($request->filled('parcours'), fn ($q) => $q->whereKey($request->integer('parcours')))
            ->orderByDesc('id_annee_aca')
            ->first();
    }

    private function etudiant(Request $request)
    {
        $etudiant = $request->user()->etudiants;
        abort_if(!$etudiant, 404, "Aucun dossier étudiant n'est rattaché à ce compte.");

        return $etudiant;
    }
}
