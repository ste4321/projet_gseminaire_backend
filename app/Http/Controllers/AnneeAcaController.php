<?php

namespace App\Http\Controllers;

use App\Models\AnneeAca;
use App\Models\Annonce;
use App\Models\EtudiantParcours;
use App\Models\ProgrammeMatiere;
use App\Models\PublicationNote;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnneeAcaController extends Controller
{
    /**
     * Années, de la plus récente à la plus ancienne, avec leurs chiffres (inscrits, matières au programme, annonces)
     * et l'année en cours (AnneeAca::courante : la plus récente qui a des inscrits).
     */
    public function index()
    {
        $courante = AnneeAca::courante()?->id;

        return AnneeAca::withCount(['parcours as inscrits_count', 'programme as matieres_count', 'annonces'])
            ->orderByDesc('annee_aca')
            ->orderByDesc('id')
            ->get()
            ->each(fn (AnneeAca $a) => $a->setAttribute('courante', $a->id === $courante));
    }

    /**
     * POST /api/annee_aca : nouvelle année académique (« 2026-2027 »), sans attendre l'import des étudiants.
     * Elle reprend le programme de l'année précédente (voir AnneeAca::booted) et devient l'année par défaut :
     * les étudiants ajoutés à la main y sont inscrits.
     */
    public function store(Request $request)
    {
        $annee = AnneeAca::create($this->validated($request));
        $reprises = ProgrammeMatiere::where('id_annee_aca', $annee->id)->count();

        return response()->json([
            'message' => "Année {$annee->annee_aca} créée."
                . ($reprises > 0 ? " {$reprises} matière(s) reprise(s) du programme précédent." : ' Son programme est vide : ajoutez-y les matières.'),
            'annee'   => $annee,
            'matieres_reprises' => $reprises,
        ], 201);
    }

    /** PUT /api/annee_aca/{id} : correction du libellé (faute de frappe) ; les inscriptions et notes restent rattachées */
    public function update(Request $request, $id)
    {
        $annee = AnneeAca::findOrFail($id);
        $annee->update($this->validated($request, $annee));

        return response()->json(['message' => "Année renommée en {$annee->annee_aca}.", 'annee' => $annee]);
    }

    /**
     * DELETE /api/annee_aca/{id} : uniquement une année sans inscription, annonce ni publication de notes
     * (typiquement créée par erreur ou en double). La suppression effacerait sinon ces données en cascade.
     * Son programme (matières reprises automatiquement) part avec elle.
     */
    public function destroy($id)
    {
        $annee = AnneeAca::findOrFail($id);

        $liens = array_filter([
            'inscription(s)' => EtudiantParcours::where('id_annee_aca', $annee->id)->count(),
            'annonce(s)' => Annonce::where('id_annee_aca', $annee->id)->count(),
            'publication(s) de notes' => PublicationNote::where('id_annee_aca', $annee->id)->count(),
        ]);
        if ($liens) {
            $detail = implode(', ', array_map(fn ($n, $quoi) => "{$n} {$quoi}", $liens, array_keys($liens)));
            return response()->json(['message' => "Impossible de supprimer {$annee->annee_aca} : {$detail} y sont rattachées."], 409);
        }

        $annee->delete();

        return response()->json(['message' => "Année {$annee->annee_aca} supprimée."]);
    }

    private function validated(Request $request, ?AnneeAca $annee = null): array
    {
        $request->merge(['annee_aca' => preg_replace('/\s+/', '', (string) $request->input('annee_aca'))]);

        return $request->validate([
            'annee_aca' => [
                // bail : la vérification « l'année suit » ne s'exécute que sur un format valide
                'bail', 'required', 'regex:/^[0-9]{4}-[0-9]{4}$/', Rule::unique('annee_aca', 'annee_aca')->ignore($annee?->id),
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
    }
}
