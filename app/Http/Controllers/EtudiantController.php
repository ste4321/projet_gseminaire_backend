<?php

namespace App\Http\Controllers;

use App\Models\Etudiant;
use Illuminate\Http\Request;
use App\Models\EtudiantParcours;
use Illuminate\Support\Facades\DB;
use App\Models\Niveau;
use App\Models\AnneeAca;
use App\Models\Password;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;

class EtudiantController extends Controller
{
    public function index()
    {
        return Etudiant::orderBy('id')->get();
    }

    // public function store(Request $request)
    // {
    //     $validated = $request->validate([
    //         'matricule'    => 'required|string|max:50',
    //         'nom_prenom'   => 'required|string|max:255',
    //         'diocese'      => 'nullable|string|max:255',
    //         'email'        => 'nullable|email',
    //         'telephone'    => 'nullable|string|max:30',
    //         'niveau'       => 'required|string|in:L1,L2,L3,M1,M2',
    //         'filiere'      => 'nullable|string|max:255',
    //     ]);

    //     // 🔍 Vérifier si un étudiant avec ce matricule existe déjà
    //     if (Etudiant::where('matricule', $validated['matricule'])->exists()) {
    //         return response()->json([
    //             'message' => "Le matricule « {$validated['matricule']} » existe déjà dans la base de données.",
    //         ], 409); // 409 Conflict
    //     }

    //     try {
    //         DB::beginTransaction();

    //         $etudiant = Etudiant::create([
    //             'matricule'    => $validated['matricule'],
    //             'nom_prenom'   => $validated['nom_prenom'],
    //             'diocese'      => $validated['diocese'] ?? null,
    //             'email'        => $validated['email'] ?? null,
    //             'telephone'    => $validated['telephone'] ?? null,
    //         ]);

    //         $niveau = Niveau::where('niveau', $validated['niveau'])->firstOrFail();
    //         $annee = AnneeAca::orderBy('id', 'desc')->firstOrFail();

    //         EtudiantParcours::create([
    //             'id_etudiant'       => $etudiant->id,
    //             'id_niveau'         => $niveau->id,
    //             'id_annee_aca'      => $annee->id,
    //             'statut'            => 'inscrit',
    //             'date_inscription'  => now(),
    //             'matricule'         => $validated['matricule'],
    //         ]);

    //         DB::commit();

    //         return response()->json([
    //             'message' => 'Étudiant ajouté avec succès.',
    //             'etudiant' => $etudiant,
    //         ], 201);

    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         return response()->json([
    //             'message' => 'Erreur lors de la création de l\'étudiant.',
    //             'exception' => get_class($e),
    //             'error' => $e->getMessage(),
    //         ], 500);
    //     }
    // }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'matricule'    => 'required|string|max:50',
            'nom_prenom'   => 'required|string|max:255',
            'diocese'      => 'nullable|string|max:255',
            'email'        => 'nullable|email',
            'telephone'    => 'nullable|string|max:30',
            'niveau'       => 'required|string|in:L1,L2,L3,M1,M2',
            'filiere'      => 'nullable|string|max:255',
        ]);

        if (Etudiant::where('matricule', $validated['matricule'])->exists()) {
            return response()->json([
                'message' => "Le matricule « {$validated['matricule']} » existe déjà dans la base de données.",
            ], 409);
        }

        try {
            DB::beginTransaction();

            // 1. Générer mot de passe
            $randomPassword = Str::random(6);
            $hashedPassword = Hash::make($randomPassword);

            // 2. Créer utilisateur user_cession
            $user = User::create([
                'email'       => $validated['email'] ?? "{$validated['matricule']}@fake.email",
                'password'    => $hashedPassword,
                'is_verified' => false,
                'role'        => 'etudiant',
            ]);

            // 3. Créer mot de passe dans passwords
            Password::create([
                'id_user_cession' => $user->id,
                'password'        => $randomPassword,
                'psw_scrypt'      => $hashedPassword,
            ]);

            // 4. Créer étudiant
            $etudiant = Etudiant::create([
                'matricule'         => $validated['matricule'],
                'nom_prenom'        => $validated['nom_prenom'],
                'diocese'           => $validated['diocese'] ?? null,
                'email'             => $validated['email'] ?? null,
                'telephone'         => $validated['telephone'] ?? null,
                'id_user_cession'   => $user->id,
            ]);

            // 5. Créer ligne dans etudiant_parcours
            $niveau = Niveau::where('niveau', $validated['niveau'])->firstOrFail();
            $annee = AnneeAca::orderBy('id', 'desc')->firstOrFail();

            EtudiantParcours::create([
                'id_etudiant'       => $etudiant->id,
                'id_niveau'         => $niveau->id,
                'id_annee_aca'      => $annee->id,
                'statut'            => 'inscrit',
                'date_inscription'  => now(),
                'matricule'         => $validated['matricule'],
            ]);

            DB::commit();

            return response()->json([
                'message' => "Étudiant ajouté avec succès.",
                'etudiant' => $etudiant,
                'user' => $user,
                'mot_de_passe' => $randomPassword // à afficher une seule fois si besoin
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Erreur lors de la création de l\'étudiant.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        return Etudiant::findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $etudiant = Etudiant::findOrFail($id);

        $validated = $request->validate([
            'matricule'    => "required|string|max:50|unique:etudiants,matricule,{$id}",
            'nom_prenom'   => 'required|string|max:255',
            'diocese'      => 'nullable|string|max:255',
            'email'        => 'nullable|email',
            'telephone'    => 'nullable|string|max:30',
            'niveau'       => 'required|string|in:L1,L2,L3,M1,M2',
        ]);

        DB::beginTransaction();

        try {
            // ✅ Mise à jour des infos de base
            $etudiant->update([
                'matricule'   => $validated['matricule'],
                'nom_prenom'  => $validated['nom_prenom'],
                'diocese'     => $validated['diocese'] ?? null,
                'email'       => $validated['email'] ?? null,
                'telephone'   => $validated['telephone'] ?? null,
            ]);

            // ✅ Récupération du niveau et de l’année académique actuelle
            $niveau = Niveau::where('niveau', $validated['niveau'])->firstOrFail();
            $annee = AnneeAca::orderBy('id', 'desc')->firstOrFail(); // dernière année

            // ✅ Mise à jour du niveau dans etudiant_parcours
            $parcours = EtudiantParcours::where('id_etudiant', $etudiant->id)
                        ->where('id_annee_aca', $annee->id)
                        ->first();

            if ($parcours) {
                $parcours->update([
                    'id_niveau' => $niveau->id,
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Étudiant mis à jour avec succès.',
                'etudiant' => $etudiant,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Erreur lors de la mise à jour de l\'étudiant.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        $etudiant = Etudiant::findOrFail($id);
        $etudiant->delete();

        return response()->json(['message' => 'Étudiant supprimé avec succès.']);
    }

    public function parcoursParAnneeEtNiveau($id_annee, $id_niveau)
    {
        $parcours = EtudiantParcours::with('etudiant')
            ->where('id_annee_aca', $id_annee)
            ->where('id_niveau', $id_niveau)
            ->get();

        // Transformer les données pour retourner les infos utiles
        $result = $parcours->map(function ($p) {
            return [
                'id' => $p->etudiant->id,
                'nom_prenom' => $p->etudiant->nom_prenom,
                'matricule' => $p->etudiant->matricule,
                'diocese' => $p->etudiant->diocese,
                'email' => $p->etudiant->email,
                'telephone' => $p->etudiant->telephone,
            ];
        });

        return response()->json($result);
    }
}
