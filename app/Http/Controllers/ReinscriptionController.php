<?php

namespace App\Http\Controllers;

use App\Models\AnneeAca;
use App\Services\ConfirmationService;
use App\Services\ReinscriptionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Assistant de réinscription : passage des étudiants d'une année académique à la suivante
 * (voir ReinscriptionService). Application soumise au code de confirmation (action « reinscrire »).
 */
class ReinscriptionController extends Controller
{
    public function __construct(private ReinscriptionService $reinscriptions)
    {
    }

    /** GET /api/reinscriptions?source=&cible= : une proposition par étudiant de l'année source */
    public function index(Request $request)
    {
        [$source, $cible] = $this->annees($request);

        return response()->json([
            'source'        => $source->only(['id', 'annee_aca']),
            'cible'         => $cible->only(['id', 'annee_aca']),
            'propositions'  => $this->reinscriptions->propositions($source, $cible),
        ]);
    }

    /** POST /api/reinscriptions : enregistre les décisions (inscriptions de l'année cible, sorties) */
    public function store(Request $request, ConfirmationService $confirmations)
    {
        [$source, $cible] = $this->annees($request);
        $donnees = $request->validate([
            'decisions'                => 'required|array|min:1',
            'decisions.*.parcours_id'  => 'required|integer',
            'decisions.*.decision'     => ['required', Rule::in(ReinscriptionService::DECISIONS)],
            'decisions.*.niveau_cible' => 'nullable|integer|exists:niveaux,id',
            'decisions.*.motif'        => 'nullable|string|max:500',
        ], [
            'decisions.required' => 'Aucune décision à enregistrer.',
        ]);
        $confirmations->exiger($request->user(), 'reinscrire', $request->input('code_confirmation'));

        $bilan = $this->reinscriptions->appliquer($source, $cible, $donnees['decisions']);
        $inscrits = $bilan[ReinscriptionService::PASSAGE] + $bilan[ReinscriptionService::REDOUBLEMENT];

        return response()->json([
            'message' => "{$inscrits} étudiant" . ($inscrits > 1 ? 's' : '') . " inscrit" . ($inscrits > 1 ? 's' : '') . " en {$cible->annee_aca}.",
            'bilan'   => $bilan,
        ]);
    }

    /** @return array{0: AnneeAca, 1: AnneeAca} */
    private function annees(Request $request): array
    {
        $ids = $request->validate([
            'source' => 'required|integer|exists:annee_aca,id',
            'cible'  => 'required|integer|exists:annee_aca,id|different:source',
        ], [
            'cible.different' => "L'année d'arrivée doit être différente de l'année de départ.",
        ]);
        $source = AnneeAca::findOrFail($ids['source']);
        $cible = AnneeAca::findOrFail($ids['cible']);

        // Pas de saut d'année : de 2025-2026, on ne passe qu'en 2026-2027
        $debut = (int) explode('-', $source->annee_aca)[0];
        $suivante = ($debut + 1) . '-' . ($debut + 2);
        abort_if(
            $cible->annee_aca !== $suivante,
            422,
            "Les étudiants de {$source->annee_aca} ne peuvent être réinscrits qu'en {$suivante}, l'année qui suit."
        );

        return [$source, $cible];
    }
}
