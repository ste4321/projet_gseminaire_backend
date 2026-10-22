<?php

namespace App\Http\Controllers;

use App\Models\AnneeAca;
use App\Models\Annonce;
use App\Models\EtudiantParcours;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AnnonceController extends Controller
{
    // 🔹 Liste toutes les annonces avec relations
    public function index(Request $request)
    {
        $query = Annonce::with(['niveau', 'anneeAca'])->orderByDesc('created_at');

        // Étudiant : uniquement les annonces de son année et de son niveau (ou destinées à tous les niveaux)
        if ($request->user()->role === 'etudiant') {
            $parcours = EtudiantParcours::where('id_etudiant', $request->user()->etudiants?->id)
                ->orderByDesc('id_annee_aca')
                ->first();

            if (!$parcours) {
                return response()->json([]);
            }

            $query->where('id_annee_aca', $parcours->id_annee_aca)
                ->where(fn ($q) => $q->whereNull('id_niveau')->orWhere('id_niveau', $parcours->id_niveau));
        }

        return response()->json($query->get());
    }

    // 🔹 Créer une annonce
    public function store(Request $request)
    {
        $validated = $this->validated($request);

        // Année non précisée : l'année en cours, celle des étudiants actuellement inscrits
        if (empty($validated['id_annee_aca'])) {
            $annee = AnneeAca::courante();
            if (!$annee) {
                return response()->json(['message' => "Aucune année académique n'est encore enregistrée."], 422);
            }
            $validated['id_annee_aca'] = $annee->id;
        }

        // Gestion du fichier joint
        if ($request->hasFile('fichier')) {
            $path = $request->file('fichier')->store('annonces', 'public');
            $validated['fichier'] = asset('storage/' . $path);
        }

        $annonce = Annonce::create($validated);
        return response()->json($annonce, 201);
    }

    // 🔹 Affiche une seule annonce
    public function show($id)
    {
        $annonce = Annonce::with(['niveau', 'anneeAca'])->findOrFail($id);
        return response()->json($annonce);
    }

    // 🔹 Modifier une annonce
    public function update(Request $request, $id)
    {
        $annonce = Annonce::findOrFail($id);

        $validated = $this->validated($request);
        // Année non précisée : l'annonce garde la sienne
        if (empty($validated['id_annee_aca'])) {
            unset($validated['id_annee_aca']);
        }

        // Nouveau fichier : remplace l'ancien. Case « retirer le fichier » : l'annonce n'a plus de pièce jointe
        if ($request->hasFile('fichier')) {
            $this->supprimerFichier($annonce);
            $path = $request->file('fichier')->store('annonces', 'public');
            $validated['fichier'] = asset('storage/' . $path);
        } elseif ($request->boolean('supprimer_fichier')) {
            $this->supprimerFichier($annonce);
            $validated['fichier'] = null;
        }

        $annonce->update($validated);
        return response()->json($annonce);
    }

    // 🔹 Supprimer une annonce
    public function destroy($id)
    {
        $annonce = Annonce::findOrFail($id);

        $this->supprimerFichier($annonce);
        $annonce->delete();
        return response()->json(['message' => 'Annonce supprimée avec succès.']);
    }

    /** Supprime du disque le fichier joint (s'il est stocké par l'application) */
    private function supprimerFichier(Annonce $annonce): void
    {
        if ($annonce->fichier && str_starts_with($annonce->fichier, asset('storage/'))) {
            Storage::disk('public')->delete(str_replace(asset('storage/'), '', $annonce->fichier));
        }
    }

    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'titre'        => ['required', 'string', 'max:255'],
            'description'  => ['required', 'string', 'max:50000'],
            'fichier'      => ['nullable', 'file', 'mimes:zip,doc,docx,pdf,xlsx,jpg,jpeg,png', 'max:10240'],
            'id_annee_aca' => ['nullable', 'exists:annee_aca,id'], // vide = année en cours (ajout) / inchangée (modification)
            'id_niveau'    => ['nullable', 'exists:niveaux,id'], // vide = tous les niveaux
            'supprimer_fichier' => ['nullable', 'boolean'],
        ], [
            'titre.required'       => "Le titre de l'annonce est obligatoire.",
            'description.required' => "Le contenu de l'annonce est obligatoire.",
            'fichier.mimes'        => 'Formats acceptés : PDF, Word, Excel, ZIP, JPG, PNG.',
            'fichier.max'          => 'Le fichier ne doit pas dépasser 10 Mo.',
        ]);

        // L'expéditeur est déterminé par le serveur (non falsifiable depuis le navigateur)
        $user = $request->user();
        $validated['expediteur'] = $user->role === 'admin'
            ? 'Administration'
            : ($user->enseignant?->nom_prenom ?? $user->email);

        return $validated;
    }
}
