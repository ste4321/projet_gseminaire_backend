<?php

namespace App\Http\Controllers;

use App\Models\AnneeAca;
use App\Models\Etudiant;
use App\Models\EtudiantParcours;
use App\Models\Niveau;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EtudiantController extends Controller
{
    public function __construct(private AccountService $accounts)
    {
    }

    public function index()
    {
        return Etudiant::orderBy('id')->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules(), $this->messages());

        if ($validated['email'] ?? null) {
            if ($this->accounts->emailTaken($validated['email'])) {
                return response()->json([
                    'message' => 'Cet email est déjà utilisé par un autre compte.',
                    'errors'  => ['email' => ['Cet email est déjà utilisé par un autre compte.']],
                ], 422);
            }
        }

        $annee = AnneeAca::orderByDesc('id')->first();
        if (!$annee) {
            return response()->json(['message' => "Aucune année académique n'est configurée."], 422);
        }
        $niveau = Niveau::where('niveau', $validated['niveau'])->firstOrFail();

        try {
            [$etudiant, $user, $motDePasse] = DB::transaction(function () use ($validated, $annee, $niveau) {
                [$user, $motDePasse] = $this->accounts->create('etudiant', $validated['email'] ?? null, $validated['matricule']);

                $etudiant = Etudiant::create([
                    'matricule'       => $validated['matricule'],
                    'nom_prenom'      => $validated['nom_prenom'],
                    'diocese'         => $validated['diocese'] ?? null,
                    'email'           => $validated['email'] ?? null,
                    'telephone'       => $validated['telephone'] ?? null,
                    'id_user_cession' => $user->id,
                ]);

                EtudiantParcours::create([
                    'id_etudiant'      => $etudiant->id,
                    'id_niveau'        => $niveau->id,
                    'id_annee_aca'     => $annee->id,
                    'statut'           => 'inscrit',
                    'date_inscription' => now(),
                    'matricule'        => $etudiant->matricule,
                ]);

                return [$etudiant, $user, $motDePasse];
            });
        } catch (\Throwable $e) {
            return $this->serverError("Erreur lors de la création de l'étudiant.", $e);
        }

        return response()->json([
            'message'      => 'Étudiant ajouté avec succès.',
            'etudiant'     => $etudiant,
            'user'         => $user,
            'mot_de_passe' => $motDePasse, // affiché une seule fois à l'admin
        ], 201);
    }

    /** GET /api/etudiants/{id} : dossier et inscriptions (la plus récente d'abord), avec le nombre de notes saisies */
    public function show($id)
    {
        return Etudiant::with([
            'parcours' => fn ($q) => $q
                ->withCount(['notes as notes_saisies' => fn ($n) => $n->whereNotNull('note')])
                ->orderByDesc('id_annee_aca'),
            'parcours.niveau',
            'parcours.annee_academique',
        ])->findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $etudiant = Etudiant::findOrFail($id);
        $validated = $request->validate($this->rules($etudiant), $this->messages());

        if (($validated['email'] ?? null) && $this->accounts->emailTaken($validated['email'], $etudiant->id_user_cession)) {
            return response()->json([
                'message' => 'Cet email est déjà utilisé par un autre compte.',
                'errors'  => ['email' => ['Cet email est déjà utilisé par un autre compte.']],
            ], 422);
        }

        try {
            DB::transaction(function () use ($etudiant, $validated) {
                $etudiant->update([
                    'matricule'  => $validated['matricule'],
                    'nom_prenom' => $validated['nom_prenom'],
                    'diocese'    => $validated['diocese'] ?? null,
                    'email'      => $validated['email'] ?? null,
                    'telephone'  => $validated['telephone'] ?? null,
                ]);

                // L'email sert d'identifiant de connexion : on garde le compte synchronisé
                if (($validated['email'] ?? null) && $etudiant->id_user_cession) {
                    User::whereKey($etudiant->id_user_cession)->update(['email' => strtolower($validated['email'])]);
                }

                // Niveau de l'année académique en cours
                $niveau = Niveau::where('niveau', $validated['niveau'])->firstOrFail();
                $annee = AnneeAca::orderByDesc('id')->first();
                if ($annee) {
                    EtudiantParcours::where('id_etudiant', $etudiant->id)
                        ->where('id_annee_aca', $annee->id)
                        ->update(['id_niveau' => $niveau->id, 'matricule' => $etudiant->matricule]);
                }
            });
        } catch (\Throwable $e) {
            return $this->serverError("Erreur lors de la mise à jour de l'étudiant.", $e);
        }

        return response()->json([
            'message'  => 'Étudiant mis à jour avec succès.',
            'etudiant' => $etudiant->fresh(),
        ]);
    }

    public function destroy($id)
    {
        $etudiant = Etudiant::findOrFail($id);

        try {
            DB::transaction(function () use ($etudiant) {
                $userId = $etudiant->id_user_cession;
                $etudiant->delete(); // parcours et notes supprimés par cascade
                $this->accounts->delete($userId); // plus de compte orphelin capable de se connecter
            });
        } catch (\Throwable $e) {
            return $this->serverError("Erreur lors de la suppression de l'étudiant.", $e);
        }

        return response()->json(['message' => 'Étudiant supprimé avec succès.']);
    }

    private function rules(?Etudiant $etudiant = null): array
    {
        return [
            'matricule'  => ['required', 'string', 'max:50', Rule::unique('etudiants', 'matricule')->ignore($etudiant?->id)],
            'nom_prenom' => ['required', 'string', 'max:255'],
            'diocese'    => ['nullable', 'string', 'max:255'],
            'email'      => ['nullable', 'email', 'max:255', Rule::unique('etudiants', 'email')->ignore($etudiant?->id)],
            'telephone'  => ['nullable', 'string', 'max:30'],
            'niveau'     => ['required', 'string', Rule::exists('niveaux', 'niveau')],
        ];
    }

    private function messages(): array
    {
        return [
            'matricule.unique' => 'Ce matricule existe déjà.',
            'email.unique'     => 'Cet email est déjà utilisé par un autre étudiant.',
            'niveau.exists'    => "Ce niveau n'existe pas.",
        ];
    }
}
