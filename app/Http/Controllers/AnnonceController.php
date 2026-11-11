<?php

namespace App\Http\Controllers;

use App\Events\AnnonceMiseAJour;
use App\Models\AnneeAca;
use App\Models\Annonce;
use App\Models\EtudiantParcours;
use App\Services\DiffusionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AnnonceController extends Controller
{
    // 🔹 Liste toutes les annonces avec relations
    /**
     * GET /api/annonces — annonces visibles par l'utilisateur, les plus récentes d'abord.
     * - ?page=N[&per_page=8][&q=texte][&annee=<id>][&destinataires=tous|enseignants|etudiants|niveau:<id>] :
     *   une page, filtrée par le serveur ; total = annonces trouvées, total_tout = sans filtre.
     * - ?recentes=N : seulement les N plus récentes (cloche, accueil) ;
     * - sans paramètre : toutes (ancien format, à éviter : la liste grandit chaque année).
     */
    public function index(Request $request)
    {
        $filtres = $request->validate([
            'page'          => 'nullable|integer|min:1',
            'per_page'      => 'nullable|integer|min:1|max:50',
            'recentes'      => 'nullable|integer|min:1|max:100',
            'q'             => 'nullable|string|max:100',
            'annee'         => 'nullable|integer',
            'destinataires' => ['nullable', 'string', 'regex:/^(tous|enseignants|etudiants|niveau:\d+)$/'],
        ]);

        $query = $this->visibles($request);
        if ($query === null) {
            return response()->json(isset($filtres['page']) ? $this->pageVide() : []);
        }

        if (isset($filtres['recentes'])) {
            return response()->json($query->limit($filtres['recentes'])->get());
        }

        if (!isset($filtres['page'])) {
            return response()->json($query->get());
        }

        $totalTout = (clone $query)->count();
        $this->filtrer($query, $filtres);
        $page = $query->paginate($filtres['per_page'] ?? 8);

        return response()->json([
            'data'       => $page->items(),
            'page'       => $page->currentPage(),
            'pages'      => $page->lastPage(),
            'total'      => $page->total(),
            'total_tout' => $totalTout,
        ]);
    }

    private function pageVide(): array
    {
        return ['data' => [], 'page' => 1, 'pages' => 0, 'total' => 0, 'total_tout' => 0];
    }

    /** Recherche (titre, contenu, expéditeur), année et destinataires — mêmes règles que les filtres de la page Annonces */
    private function filtrer($query, array $filtres): void
    {
        if ($texte = trim($filtres['q'] ?? '')) {
            $motif = '%' . addcslashes($texte, '%_\\') . '%';
            $query->where(fn ($w) => $w
                ->where('titre', 'ilike', $motif)
                ->orWhere('description', 'ilike', $motif)
                ->orWhere('expediteur', 'ilike', $motif));
        }
        if (!empty($filtres['annee'])) {
            $query->where('id_annee_aca', $filtres['annee']);
        }
        $choix = $filtres['destinataires'] ?? null;
        if ($choix && str_starts_with($choix, 'niveau:')) {
            // Étudiants d'un niveau précis
            $query->where(fn ($w) => $w->where('destinataires', Annonce::ETUDIANTS)->orWhereNull('destinataires'))
                ->where('id_niveau', (int) substr($choix, 7));
        } elseif ($choix === Annonce::ETUDIANTS) {
            // Étudiants de tous les niveaux
            $query->where(fn ($w) => $w->where('destinataires', Annonce::ETUDIANTS)->orWhereNull('destinataires'))
                ->whereNull('id_niveau');
        } elseif ($choix) {
            $query->where('destinataires', $choix);
        }
    }

    /** Annonces que l'utilisateur a le droit de voir (null : aucune) */
    private function visibles(Request $request)
    {
        $query = Annonce::with(['niveau', 'anneeAca'])->orderByDesc('created_at')->orderByDesc('id');

        // Étudiant : uniquement les annonces qui lui sont destinées, de son année et de son niveau (ou de tous les niveaux)
        if ($request->user()->role === 'etudiant') {
            $parcours = EtudiantParcours::where('id_etudiant', $request->user()->etudiants?->id)
                ->orderByDesc('id_annee_aca')
                ->first();

            if (!$parcours) {
                return null;
            }

            $query->whereIn('destinataires', [Annonce::ETUDIANTS, Annonce::TOUS])
                ->where('id_annee_aca', $parcours->id_annee_aca)
                ->where(fn ($q) => $q->whereNull('id_niveau')->orWhere('id_niveau', $parcours->id_niveau));
        }

        // Enseignant : les annonces destinées aux enseignants ou à tout le monde
        if ($request->user()->role === 'prof') {
            $query->whereIn('destinataires', [Annonce::ENSEIGNANTS, Annonce::TOUS]);
        }

        return $query;
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

        // Fichier joint : la base garde le chemin public (/storage/annonces/…), l'application le complète
        // avec l'adresse du serveur (comme l'emploi du temps)
        if ($request->hasFile('fichier')) {
            $validated['fichier'] = $this->enregistrerFichier($request);
        }

        $annonce = Annonce::create($validated);
        $this->diffuser(AnnonceMiseAJour::CREEE, $annonce, $annonce->idsUtilisateursConcernes());
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
            $validated['fichier'] = $this->enregistrerFichier($request);
        } elseif ($request->boolean('supprimer_fichier')) {
            $this->supprimerFichier($annonce);
            $validated['fichier'] = null;
        }

        // Destinataires d'avant et d'après : ceux qui ne sont plus concernés voient aussi l'annonce disparaître
        $avant = $annonce->idsUtilisateursConcernes();
        $annonce->update($validated);
        $this->diffuser(AnnonceMiseAJour::MODIFIEE, $annonce, array_merge($avant, $annonce->idsUtilisateursConcernes()));
        return response()->json($annonce);
    }

    // 🔹 Supprimer une annonce
    public function destroy($id)
    {
        $annonce = Annonce::findOrFail($id);

        $destinataires = $annonce->idsUtilisateursConcernes(); // avant la suppression
        $this->supprimerFichier($annonce);
        $annonce->delete();
        $this->diffuser(AnnonceMiseAJour::SUPPRIMEE, $annonce, $destinataires);
        return response()->json(['message' => 'Annonce supprimée avec succès.']);
    }

    /**
     * Prévient en direct les destinataires (publication, modification, suppression). Si le serveur
     * WebSocket est arrêté, l'opération est quand même faite : ils la verront à leur prochain chargement.
     */
    private function diffuser(string $action, Annonce $annonce, array $idsUtilisateurs): void
    {
        DiffusionService::envoyer(
            new AnnonceMiseAJour($action, $annonce, array_values(array_unique($idsUtilisateurs))),
            ['annonce_id' => $annonce->id, 'action' => $action]
        );
    }

    private function enregistrerFichier(Request $request): string
    {
        return '/storage/' . $request->file('fichier')->store('annonces', 'public');
    }

    /**
     * Supprime du disque le fichier joint stocké par l'application. Accepte le chemin public (/storage/annonces/x)
     * comme les adresses complètes des anciennes annonces (http://…/storage/annonces/x).
     */
    private function supprimerFichier(Annonce $annonce): void
    {
        if ($annonce->fichier && preg_match('#/storage/(annonces/[^?\#]+)$#', $annonce->fichier, $m)) {
            Storage::disk('public')->delete($m[1]);
        }
    }

    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'titre'        => ['required', 'string', 'max:255'],
            'description'  => ['required', 'string', 'max:50000'],
            'fichier'      => ['nullable', 'file', 'mimes:zip,doc,docx,pdf,xlsx,jpg,jpeg,png', 'max:10240'],
            'id_annee_aca' => ['nullable', 'exists:annee_aca,id'], // vide = année en cours (ajout) / inchangée (modification)
            'destinataires' => ['nullable', 'in:' . implode(',', Annonce::DESTINATAIRES)], // vide = étudiants
            'id_niveau'    => ['nullable', 'exists:niveaux,id'], // vide = tous les niveaux (étudiants uniquement)
            'supprimer_fichier' => ['nullable', 'boolean'],
        ], [
            'titre.required'       => "Le titre de l'annonce est obligatoire.",
            'description.required' => "Le contenu de l'annonce est obligatoire.",
            'fichier.mimes'        => 'Formats acceptés : PDF, Word, Excel, ZIP, JPG, PNG.',
            'fichier.max'          => 'Le fichier ne doit pas dépasser 10 Mo.',
            // Fichier refusé par PHP avant Laravel (au-delà de upload_max_filesize du serveur)
            'fichier.uploaded'     => "Le fichier n'a pas pu être reçu : le serveur accepte au plus " . ini_get('upload_max_filesize') . 'o par fichier.',
            'destinataires.in'     => 'Destinataires inconnus.',
        ]);

        // Le niveau ne concerne que les annonces réservées aux étudiants
        $validated['destinataires'] = $validated['destinataires'] ?? Annonce::ETUDIANTS;
        if ($validated['destinataires'] !== Annonce::ETUDIANTS) {
            $validated['id_niveau'] = null;
        }

        // L'expéditeur est déterminé par le serveur (non falsifiable depuis le navigateur)
        $user = $request->user();
        $validated['expediteur'] = $user->role === 'admin'
            ? 'Administration'
            : ($user->enseignant?->nom_prenom ?? $user->email);

        return $validated;
    }
}
