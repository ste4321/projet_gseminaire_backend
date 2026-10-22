<?php

namespace App\Http\Controllers;

use App\Models\Matiere;
use App\Models\Niveau;
use App\Models\Note;
use App\Models\ProgrammeMatiere;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Catalogue des matières. Avec une année académique (?annee= ou id_annee_aca), chaque matière est présentée
 * avec son programme de cette année : présence, coefficient et heures de l'année, notes déjà saisies.
 */
class MatiereController extends Controller
{
    /** GET /api/matieres[?annee=<id>] */
    public function index(Request $request)
    {
        $annee = $request->validate(['annee' => 'nullable|integer|exists:annee_aca,id'])['annee'] ?? null;

        if (!$annee) {
            return Matiere::with(['niveau', 'semestre'])->orderBy('id')->get();
        }

        return Matiere::with(['niveau', 'semestre', 'programmes.annee'])
            ->withCount([
                'notes as notes_total',
                'notes as notes_annee' => fn ($q) => $q->whereHas('etudiantParcours', fn ($p) => $p->where('id_annee_aca', $annee)),
            ])
            ->orderBy('id')
            ->get()
            ->map(fn ($m) => $this->pourAnnee($m, (int) $annee));
    }

    /** $niveau : numéro (1 pour L1) ou libellé complet (L1, M1...) */
    public function getMatieresByNiveau($niveau)
    {
        $libelle = ctype_digit((string) $niveau) ? 'L' . $niveau : strtoupper($niveau);
        $niveauRecord = Niveau::where('niveau', $libelle)->first();

        if (!$niveauRecord) {
            return response()->json(['message' => "Le niveau {$libelle} n'existe pas."], 404);
        }

        return Matiere::where('id_niveau', $niveauRecord->id)
            ->with(['niveau', 'semestre'])
            ->orderBy('matiere')
            ->get();
    }

    /** Nouvelle matière du catalogue, mise directement au programme de l'année donnée */
    public function store(Request $request)
    {
        $validated = $request->validate($this->rules(), $this->messages());
        $annee = $validated['id_annee_aca'] ?? null;
        unset($validated['id_annee_aca']);

        $matiere = DB::transaction(function () use ($validated, $annee) {
            $matiere = Matiere::create($validated);
            if ($annee) {
                ProgrammeMatiere::create([
                    'id_annee_aca' => $annee,
                    'id_matiere'   => $matiere->id,
                    'coefficient'  => $validated['coefficient'],
                    'heures'       => $validated['heures'],
                ]);
            }
            return $matiere;
        });

        return response()->json($this->reponse($matiere, $annee), 201);
    }

    public function show($id)
    {
        return Matiere::with(['niveau', 'semestre'])->findOrFail($id);
    }

    /**
     * Nom, code, niveau, semestre : communs à toutes les années (catalogue).
     * Coefficient et heures : ceux de l'année donnée (et valeurs par défaut pour les prochains ajouts).
     */
    public function update(Request $request, $id)
    {
        $matiere = Matiere::findOrFail($id);
        $validated = $request->validate($this->rules($matiere), $this->messages());
        $annee = $validated['id_annee_aca'] ?? null;
        unset($validated['id_annee_aca']);

        // Les notes déjà saisies sont rattachées à des inscriptions de ce niveau : il ne peut plus changer
        if ((int) $validated['id_niveau'] !== (int) $matiere->id_niveau && $matiere->notes()->exists()) {
            return response()->json([
                'message' => "Le niveau ne peut plus être changé : des notes sont enregistrées pour cette matière. "
                    . "Retirez-la du programme et créez une nouvelle matière dans l'autre niveau.",
            ], 409);
        }
        // Changer de semestre déplacerait la matière dans les relevés des autres années
        $semestreChange = (int) ($validated['id_semestre'] ?? 0) !== (int) ($matiere->id_semestre ?? 0);
        $notesAutresAnnees = Note::where('id_matiere', $matiere->id)
            ->when($annee, fn ($q) => $q->whereHas('etudiantParcours', fn ($p) => $p->where('id_annee_aca', '!=', $annee)))
            ->exists();
        if ($semestreChange && $notesAutresAnnees) {
            return response()->json([
                'message' => "Le semestre ne peut plus être changé : des notes d'autres années sont enregistrées pour cette matière. "
                    . "Retirez-la du programme de cette année et créez une nouvelle matière dans l'autre semestre.",
            ], 409);
        }

        DB::transaction(function () use ($matiere, $validated, $annee) {
            $matiere->update($validated);
            if ($annee) {
                ProgrammeMatiere::where('id_annee_aca', $annee)->where('id_matiere', $matiere->id)
                    ->update(['coefficient' => $validated['coefficient'], 'heures' => $validated['heures']]);
            }
        });

        return $this->reponse($matiere, $annee);
    }

    /** Suppression du catalogue : seulement pour une matière qui n'a aucune note, quelle que soit l'année */
    public function destroy($id)
    {
        $matiere = Matiere::findOrFail($id);

        $nbNotes = Note::where('id_matiere', $matiere->id)->count();
        if ($nbNotes > 0) {
            return response()->json([
                'message' => "Impossible de supprimer : {$nbNotes} note(s) sont enregistrées pour cette matière. "
                    . "Pour ne plus l'enseigner, retirez-la du programme de l'année : ses notes passées sont conservées.",
            ], 409);
        }

        $matiere->delete(); // ses lignes de programme partent avec elle
        return response()->json(['message' => 'Matière supprimée du catalogue.']);
    }

    /** Matière telle que vue depuis une année : valeurs de l'année si elle est au programme, sinon valeurs par défaut */
    private function pourAnnee(Matiere $m, int $annee): array
    {
        $programme = $m->programmes->firstWhere('id_annee_aca', $annee);

        return [
            'id'            => $m->id,
            'matiere'       => $m->matiere,
            'code_matiere'  => $m->code_matiere,
            'id_niveau'     => $m->id_niveau,
            'id_semestre'   => $m->id_semestre,
            'niveau'        => $m->niveau,
            'semestre'      => $m->semestre,
            'created_at'    => $m->created_at,
            'au_programme'  => (bool) $programme,
            'coefficient'   => $programme ? $programme->coefficient : (float) str_replace(',', '.', (string) $m->coefficient),
            'heures'        => $programme ? $programme->heures : (int) $m->heures,
            'notes_annee'   => (int) $m->notes_annee,
            'notes_total'   => (int) $m->notes_total,
            // Années où la matière a été enseignée, avec leur coefficient (de la plus récente à la plus ancienne)
            'historique'    => $m->programmes
                ->sortByDesc('id_annee_aca')
                ->map(fn ($p) => [
                    'id_annee_aca' => $p->id_annee_aca,
                    'annee_aca'    => $p->annee?->annee_aca,
                    'coefficient'  => $p->coefficient,
                    'heures'       => $p->heures,
                ])->values(),
        ];
    }

    private function reponse(Matiere $matiere, $annee)
    {
        if (!$annee) {
            return $matiere->load(['niveau', 'semestre']);
        }

        $matiere->load(['niveau', 'semestre', 'programmes.annee'])->loadCount([
            'notes as notes_total',
            'notes as notes_annee' => fn ($q) => $q->whereHas('etudiantParcours', fn ($p) => $p->where('id_annee_aca', $annee)),
        ]);
        return $this->pourAnnee($matiere, (int) $annee);
    }

    private function rules(?Matiere $matiere = null): array
    {
        $niveau = request('id_niveau');
        $semestre = request('id_semestre');

        return [
            'matiere'      => ['required', 'string', 'max:255'],
            'heures'       => ['required', 'integer', 'min:0', 'max:1000'],
            'id_niveau'    => ['required', 'exists:niveaux,id'],
            'id_semestre'  => ['nullable', 'exists:semestres,id'],
            // Facultatif. Le même code peut servir à plusieurs niveaux ou semestres (THG en L1 et en L2) :
            // l'import des notes le retrouve par code + niveau + semestre (NOTES_THG_L2_S1_2025-2026.xlsx).
            // Il n'est donc unique qu'au sein d'un même niveau et d'un même semestre.
            'code_matiere' => [
                'nullable', 'string', 'max:50',
                Rule::unique('matieres', 'code_matiere')
                    ->where('id_niveau', $niveau)
                    ->where(fn ($q) => $semestre ? $q->where('id_semestre', $semestre) : $q->whereNull('id_semestre'))
                    ->ignore($matiere?->id),
            ],
            'coefficient'  => ['required', 'numeric', 'min:0', 'max:100'],
            // Année dont le programme reçoit la matière (ajout) ou dont on modifie le coefficient (modification)
            'id_annee_aca' => ['nullable', 'integer', 'exists:annee_aca,id'],
        ];
    }

    private function messages(): array
    {
        return [
            'code_matiere.unique'   => 'Ce code est déjà utilisé par une autre matière du même niveau et du même semestre.',
        ];
    }
}
