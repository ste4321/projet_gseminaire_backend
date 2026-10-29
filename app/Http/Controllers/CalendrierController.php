<?php

namespace App\Http\Controllers;

use App\Events\CalendrierMisAJour;
use App\Models\Calendrier;
use App\Models\CalendrierEvenement;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Calendrier académique du site vitrine.
 * - GET /api/calendrier (public) : le calendrier affiché sur le site (le plus récent des publiés), ou null
 * - /api/calendriers… (admin) : saisie par année, brouillon puis publication
 * Toute modification qui touche le calendrier affiché est signalée en direct au site (CalendrierMisAJour).
 * Chaque écriture renvoie le calendrier complet (avec ses événements), pour mettre l'écran d'administration à jour.
 */
class CalendrierController extends Controller
{
    public function public()
    {
        $calendrier = Calendrier::affiche();

        return response()->json($calendrier?->load('evenements')->pourLeSite());
    }

    public function index()
    {
        return Calendrier::with('evenements')->orderByDesc('annee')->get();
    }

    /** Nouvelle année ; « copier_de » reprend les événements d'un autre calendrier, dates décalées d'autant d'années */
    public function store(Request $request)
    {
        $donnees = $this->validerCalendrier($request);
        $source = $request->filled('copier_de') ? Calendrier::with('evenements')->findOrFail($request->input('copier_de')) : null;

        $calendrier = DB::transaction(function () use ($donnees, $source) {
            $calendrier = Calendrier::create($donnees + ['publie' => false]);
            if ($source) {
                $ecart = (int) substr($calendrier->annee, 0, 4) - (int) substr($source->annee, 0, 4);
                $decaler = fn (?Carbon $d) => $d?->copy()->addYearsNoOverflow($ecart)->format('Y-m-d');
                foreach (['s1_debut', 's1_fin', 's2_debut', 's2_fin'] as $champ) {
                    $calendrier->{$champ} ??= $decaler($source->{$champ});
                }
                $calendrier->save();
                $calendrier->evenements()->createMany($source->evenements->map(fn ($e) => [
                    'semestre' => $e->semestre,
                    'date_debut' => $decaler($e->date_debut),
                    'date_fin' => $decaler($e->date_fin),
                    'liaison' => $e->liaison,
                    'libelle' => $e->libelle,
                ])->all());
            }
            return $calendrier;
        });

        return response()->json($calendrier->load('evenements'), 201);
    }

    /** Année, bornes des semestres et publication */
    public function update(Request $request, $id)
    {
        $calendrier = Calendrier::findOrFail($id);
        $etaitAffiche = $this->estAffiche($calendrier);

        $calendrier->update($this->validerCalendrier($request, $calendrier) + $request->validate(['publie' => ['sometimes', 'boolean']]));
        $this->signaler($etaitAffiche || $this->estAffiche($calendrier));

        return $calendrier->load('evenements');
    }

    public function destroy($id)
    {
        $calendrier = Calendrier::findOrFail($id);
        $etaitAffiche = $this->estAffiche($calendrier);
        $calendrier->delete();
        $this->signaler($etaitAffiche);

        return response()->json(['message' => "Calendrier {$calendrier->annee} supprimé."]);
    }

    // ─── Événements ──────────────────────────────────────────────────────

    public function storeEvenement(Request $request, $id)
    {
        $calendrier = Calendrier::findOrFail($id);
        $calendrier->evenements()->create($this->validerEvenement($request));
        $this->signaler($this->estAffiche($calendrier));

        return response()->json($calendrier->load('evenements'), 201);
    }

    public function updateEvenement(Request $request, $id)
    {
        $evenement = CalendrierEvenement::findOrFail($id);
        $evenement->update($this->validerEvenement($request));
        $this->signaler($this->estAffiche($evenement->calendrier));

        return $evenement->calendrier->load('evenements');
    }

    public function destroyEvenement($id)
    {
        $evenement = CalendrierEvenement::findOrFail($id);
        $calendrier = $evenement->calendrier;
        $evenement->delete();
        $this->signaler($this->estAffiche($calendrier));

        return $calendrier->load('evenements');
    }

    // ─── Validation ──────────────────────────────────────────────────────

    private function validerCalendrier(Request $request, ?Calendrier $calendrier = null): array
    {
        if ($request->has('annee')) {
            $request->merge(['annee' => preg_replace('/\s+/', '', (string) $request->input('annee'))]);
        }
        $obligatoire = $calendrier ? 'sometimes' : 'required';

        return $request->validate([
            'annee' => [
                $obligatoire, 'bail', 'regex:/^[0-9]{4}-[0-9]{4}$/',
                Rule::unique('calendriers', 'annee')->ignore($calendrier?->id),
                function ($attribut, $valeur, $echec) {
                    [$debut, $fin] = array_map('intval', explode('-', $valeur));
                    if ($fin !== $debut + 1) {
                        $echec('La deuxième année doit suivre la première (ex. 2026-2027).');
                    }
                },
            ],
            's1_debut' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            's1_fin'   => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:s1_debut'],
            's2_debut' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            's2_fin'   => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:s2_debut'],
        ], [
            'annee.required'        => "Indiquez l'année académique.",
            'annee.regex'           => 'Format attendu : 2026-2027.',
            'annee.unique'          => 'Un calendrier existe déjà pour cette année.',
            '*.date_format'         => 'Date invalide.',
            's1_fin.after_or_equal' => 'La fin du premier semestre doit suivre son début.',
            's2_fin.after_or_equal' => 'La fin du deuxième semestre doit suivre son début.',
        ]);
    }

    private function validerEvenement(Request $request): array
    {
        $donnees = $request->validate([
            'semestre'   => ['required', Rule::in(array_keys(Calendrier::TITRES_SEMESTRES))],
            'date_debut' => ['required', 'date_format:Y-m-d'],
            'date_fin'   => ['nullable', 'date_format:Y-m-d', 'after:date_debut'],
            'liaison'    => ['nullable', 'required_with:date_fin', Rule::in(CalendrierEvenement::LIAISONS)],
            'libelle'    => ['required', 'string', 'max:255'],
        ], [
            'semestre.*'          => 'Choisissez le semestre.',
            'date_debut.required' => 'Indiquez la date.',
            '*.date_format'       => 'Date invalide.',
            'date_fin.after'      => 'La date de fin doit suivre la date de début.',
            'liaison.*'           => 'Précisez s’il s’agit d’une période ou de deux jours.',
            'libelle.required'    => "Indiquez l'intitulé de l'événement.",
        ]);

        $donnees['libelle'] = trim($donnees['libelle']);
        if (empty($donnees['date_fin'])) {
            $donnees['date_fin'] = null;
            $donnees['liaison'] = null;
        } elseif ($donnees['liaison'] === 'et'
            && Carbon::parse($donnees['date_debut'])->addDay()->format('Y-m-d') !== $donnees['date_fin']) {
            // « Deux jours » : deux jours consécutifs ; au-delà, c'est une période (« du … au … »)
            throw ValidationException::withMessages([
                'date_fin' => 'Pour « Deux jours », le second jour doit être le lendemain du premier. Sinon, choisissez une période.',
            ]);
        }

        return $donnees;
    }

    private function estAffiche(?Calendrier $calendrier): bool
    {
        return $calendrier && $calendrier->publie && Calendrier::affiche()?->id === $calendrier->id;
    }

    /**
     * Prévient le site vitrine. Si le serveur WebSocket est arrêté, la modification est quand même
     * enregistrée : le site la récupérera à sa prochaine lecture.
     */
    private function signaler(bool $concerneLeSite): void
    {
        if (!$concerneLeSite) {
            return;
        }
        try {
            broadcast(new CalendrierMisAJour());
        } catch (\Throwable $e) {
            Log::warning('Diffusion en direct du calendrier impossible (serveur Reverb arrêté ?)', ['error' => $e->getMessage()]);
        }
    }
}
