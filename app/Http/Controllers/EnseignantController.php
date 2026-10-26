<?php

namespace App\Http\Controllers;

use App\Models\Enseignant;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class EnseignantController extends Controller
{
    public function __construct(private AccountService $accounts)
    {
    }

    public function index(Request $request)
    {
        // Annuaire pour les étudiants et les enseignants : les coordonnées, sans les données internes (compte lié, dates)
        $colonnes = $request->user()->role === 'admin'
            ? ['*']
            : ['id', 'nom_prenom', 'email', 'telephone', 'adresse'];

        return Enseignant::orderBy('id')->get($colonnes);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules(), $this->messages());

        if (($validated['email'] ?? null) && $this->accounts->emailTaken($validated['email'])) {
            return $this->emailTakenResponse();
        }

        try {
            [$enseignant, $user, $motDePasse] = DB::transaction(function () use ($validated) {
                [$user, $motDePasse] = $this->accounts->create('prof', $validated['email'] ?? null, $validated['nom_prenom']);

                $enseignant = Enseignant::create([
                    'nom_prenom'      => $validated['nom_prenom'],
                    'adresse'         => $validated['adresse'] ?? null,
                    'email'           => $validated['email'] ?? null,
                    'telephone'       => $validated['telephone'] ?? null,
                    'id_user_cession' => $user->id,
                ]);

                return [$enseignant, $user, $motDePasse];
            });
        } catch (\Throwable $e) {
            return $this->serverError("Erreur lors de l'ajout de l'enseignant.", $e);
        }

        return response()->json([
            'message'      => 'Enseignant ajouté avec succès.',
            'enseignant'   => $enseignant,
            'user'         => $user,
            'mot_de_passe' => $motDePasse, // affiché une seule fois à l'admin
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $enseignant = Enseignant::findOrFail($id);
        $validated = $request->validate($this->rules($enseignant), $this->messages());

        if (($validated['email'] ?? null) && $this->accounts->emailTaken($validated['email'], $enseignant->id_user_cession)) {
            return $this->emailTakenResponse();
        }

        DB::transaction(function () use ($enseignant, $validated) {
            $enseignant->update([
                'nom_prenom' => $validated['nom_prenom'],
                'adresse'    => $validated['adresse'] ?? null,
                'email'      => $validated['email'] ?? null,
                'telephone'  => $validated['telephone'] ?? null,
            ]);

            // L'email sert d'identifiant de connexion : on garde le compte synchronisé
            if (($validated['email'] ?? null) && $enseignant->id_user_cession) {
                User::whereKey($enseignant->id_user_cession)->update(['email' => strtolower($validated['email'])]);
            }
        });

        return response()->json([
            'message'    => 'Enseignant mis à jour avec succès.',
            'enseignant' => $enseignant->fresh(),
        ]);
    }

    public function destroy($id)
    {
        $enseignant = Enseignant::findOrFail($id);

        try {
            DB::transaction(function () use ($enseignant) {
                $userId = $enseignant->id_user_cession;
                $enseignant->delete();
                $this->accounts->delete($userId);
            });
        } catch (\Throwable $e) {
            return $this->serverError("Erreur lors de la suppression de l'enseignant.", $e);
        }

        return response()->json(['message' => 'Enseignant et compte associé supprimés avec succès.']);
    }

    public function showCession($id)
    {
        $enseignant = Enseignant::with('userCession')->findOrFail($id);

        return response()->json(['cession' => $enseignant->userCession]);
    }

    public function assignCession(Request $request, $id)
    {
        $enseignant = Enseignant::findOrFail($id);

        if ($enseignant->id_user_cession) {
            return response()->json(['message' => 'Cet enseignant a déjà un compte.'], 409);
        }

        $validated = $request->validate([
            'email'    => ['required', 'email', 'max:255', Rule::unique('user_cession', 'email')],
            'password' => ['required', 'string', Password::defaults()],
        ]);

        $user = User::create([
            'email'       => strtolower($validated['email']),
            'password'    => Hash::make($validated['password']),
            'role'        => 'prof',
            'is_verified' => true,
            'must_change_password' => true, // mot de passe choisi par l'admin : provisoire
        ]);

        $enseignant->update(['id_user_cession' => $user->id]);

        return response()->json(['message' => 'Compte attribué avec succès.', 'user' => $user]);
    }

    private function rules(?Enseignant $enseignant = null): array
    {
        return [
            'nom_prenom' => ['required', 'string', 'max:255'],
            'adresse'    => ['nullable', 'string', 'max:255'],
            'email'      => ['nullable', 'email', 'max:255', Rule::unique('corps_enseignant', 'email')->ignore($enseignant?->id)],
            'telephone'  => ['nullable', 'string', 'max:20'],
        ];
    }

    private function messages(): array
    {
        return ['email.unique' => 'Cet email est déjà utilisé par un autre enseignant.'];
    }

    private function emailTakenResponse()
    {
        return response()->json([
            'message' => 'Cet email est déjà utilisé par un autre compte.',
            'errors'  => ['email' => ['Cet email est déjà utilisé par un autre compte.']],
        ], 422);
    }
}
