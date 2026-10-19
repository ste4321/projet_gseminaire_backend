<?php

namespace App\Http\Controllers;

use App\Models\EtudiantParcours;
use App\Models\Niveau;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class EtudiantParcoursController extends Controller
{
    /**
     * Liste paginée des inscriptions, filtrée côté serveur :
     * GET /etudiant_parcours?annee=3&niveau=L1&q=jean&page=1&per_page=10
     *
     * Sans le paramètre `page`, renvoie la liste complète (ancien comportement, à éviter :
     * le volume grandit avec chaque promotion).
     */
    public function index(Request $request)
    {
        if (!$request->has('page')) {
            $parcours = EtudiantParcours::with(['etudiant', 'annee_academique', 'niveau'])->orderBy('id')->get();
            return response()->json($parcours);
        }

        $filtres = $request->validate([
            'annee'    => 'nullable|integer',
            'niveau'   => 'nullable|string|max:10',
            'q'        => 'nullable|string|max:100',
            'page'     => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100',
        ]);

        $requete = EtudiantParcours::query()
            ->select(['id', 'id_etudiant', 'id_annee_aca', 'id_niveau', 'statut'])
            ->with([
                'etudiant:id,matricule,nom_prenom,diocese,email,telephone,id_user_cession',
                'annee_academique:id,annee_aca',
                'niveau:id,niveau',
            ])
            ->when($filtres['annee'] ?? null, fn ($q, $annee) => $q->where('id_annee_aca', $annee))
            ->when($filtres['niveau'] ?? null, fn ($q, $niveau) => $q->whereIn('id_niveau', Niveau::where('niveau', $niveau)->select('id')))
            ->when(trim($filtres['q'] ?? ''), function ($q, $texte) {
                $motif = '%' . addcslashes($texte, '%_\\') . '%';
                $q->whereHas('etudiant', fn ($e) => $e->where(fn ($w) => $w
                    ->where('nom_prenom', 'ilike', $motif)
                    ->orWhere('matricule', 'ilike', $motif)
                    ->orWhere('email', 'ilike', $motif)
                    ->orWhere('diocese', 'ilike', $motif)
                    ->orWhere('telephone', 'ilike', $motif)));
            })
            ->orderBy('id');

        return response()->json($requete->paginate($filtres['per_page'] ?? 10));
    }
}
