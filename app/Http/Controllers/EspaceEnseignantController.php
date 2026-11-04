<?php

namespace App\Http\Controllers;

use App\Models\AnneeAca;
use App\Models\Enseignant;
use App\Models\EtudiantParcours;
use App\Models\Lecon;
use App\Models\Note;
use App\Models\ProgrammeMatiere;
use App\Models\PublicationNote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Espace de l'enseignant connecté : il n'agit que sur les matières que l'administration lui a attribuées
 * pour une année (programme_matieres.id_enseignant). L'enseignant est déterminé par son compte,
 * jamais par un identifiant envoyé par le navigateur.
 * Une fois le semestre publié, les notes sont verrouillées : seule l'administration peut retirer la publication.
 */
class EspaceEnseignantController extends Controller
{
    /** GET /api/enseignant/matieres?annee= : ses matières de l'année, avec l'avancement des notes et des leçons */
    public function matieres(Request $request)
    {
        $enseignant = $this->enseignant($request);

        $annees = AnneeAca::whereIn('id', ProgrammeMatiere::where('id_enseignant', $enseignant->id)->select('id_annee_aca'))
            ->orderByDesc('id')
            ->get(['id', 'annee_aca']);

        if ($annees->isEmpty()) {
            return response()->json(['annees' => [], 'annee' => null, 'matieres' => []]);
        }

        // Année demandée, sinon l'année en cours, sinon la dernière où il a enseigné
        $annee = $annees->firstWhere('id', $request->integer('annee'))
            ?? $annees->firstWhere('id', AnneeAca::courante()?->id)
            ?? $annees->first();

        $programmes = ProgrammeMatiere::with(['matiere.niveau', 'matiere.semestre'])
            ->where('id_annee_aca', $annee->id)
            ->where('id_enseignant', $enseignant->id)
            ->get();

        $inscrits = EtudiantParcours::actifs()->where('id_annee_aca', $annee->id)->get(['id', 'id_niveau']);
        $idsMatieres = $programmes->pluck('id_matiere');
        $notes = Note::whereIn('id_etudiant_parcours', $inscrits->pluck('id'))
            ->whereIn('id_matiere', $idsMatieres)
            ->whereNotNull('note')
            ->get(['id_matiere'])
            ->countBy('id_matiere');
        $lecons = Lecon::where('id_annee_aca', $annee->id)->whereIn('id_matiere', $idsMatieres)->get(['id_matiere'])->countBy('id_matiere');
        $publications = PublicationNote::where('id_annee_aca', $annee->id)->get(['id_niveau', 'id_semestre']);

        return response()->json([
            'annees'   => $annees,
            'annee'    => $annee->id,
            'matieres' => $programmes
                ->map(fn (ProgrammeMatiere $p) => $this->resumeMatiere($p) + [
                    'etudiants'     => $inscrits->where('id_niveau', $p->matiere->id_niveau)->count(),
                    'notes_saisies' => $notes->get($p->id_matiere, 0),
                    'lecons'        => $lecons->get($p->id_matiere, 0),
                    'publie'        => $publications->contains(fn ($c) => (int) $c->id_niveau === (int) $p->matiere->id_niveau
                        && (int) $c->id_semestre === (int) $p->matiere->id_semestre),
                ])
                ->sortBy([['niveau', 'asc'], ['matiere', 'asc']])
                ->values(),
        ]);
    }

    /** GET /api/enseignant/matieres/{id}/notes?annee= : étudiants de la matière et leurs notes */
    public function notes(Request $request, $matiereId)
    {
        $programme = $this->programme($request, (int) $matiereId, $this->anneeDemandee($request));

        $notes = Note::where('id_matiere', $programme->id_matiere)
            ->whereIn('id_etudiant_parcours', $this->inscrits($programme)->pluck('id'))
            ->pluck('note', 'id_etudiant_parcours');

        return response()->json([
            'matiere'     => $this->resumeMatiere($programme),
            'annee'       => $programme->id_annee_aca,
            'verrouillee' => $this->verrouillee($programme),
            'etudiants'   => $this->inscrits($programme)
                ->map(fn ($p) => [
                    'id_etudiant_parcours' => $p->id,
                    'matricule'            => $p->matricule ?? $p->etudiant?->matricule,
                    'nom_prenom'           => $p->etudiant?->nom_prenom,
                    'note'                 => $notes->get($p->id) === null ? null : (float) $notes->get($p->id),
                ])
                ->sortBy('nom_prenom', SORT_NATURAL | SORT_FLAG_CASE)
                ->values(),
        ]);
    }

    /** PUT /api/enseignant/matieres/{id}/notes : enregistre les notes (une note vide efface la note existante) */
    public function enregistrerNotes(Request $request, $matiereId)
    {
        $validated = $request->validate([
            'annee'                        => ['required', 'integer'],
            'notes'                        => ['required', 'array', 'min:1'],
            'notes.*.id_etudiant_parcours' => ['required', 'integer'],
            'notes.*.note'                 => ['nullable', 'numeric', 'min:0', 'max:20'],
        ], [
            'notes.*.note.numeric' => 'Chaque note doit être un nombre.',
            'notes.*.note.min'     => 'Une note ne peut pas être inférieure à 0.',
            'notes.*.note.max'     => 'Une note ne peut pas dépasser 20.',
        ]);

        $programme = $this->programme($request, (int) $matiereId, (int) $validated['annee']);

        if ($this->verrouillee($programme)) {
            return response()->json(['message' => "Ce semestre est publié : les notes ne peuvent plus être modifiées. Adressez-vous à l'administration."], 409);
        }

        $autorises = $this->inscrits($programme)->pluck('id');
        if (collect($validated['notes'])->pluck('id_etudiant_parcours')->diff($autorises)->isNotEmpty()) {
            return response()->json(['message' => "Certains étudiants ne suivent pas cette matière. Rechargez la page."], 422);
        }

        DB::transaction(function () use ($validated, $programme) {
            foreach ($validated['notes'] as $ligne) {
                $cle = ['id_etudiant_parcours' => $ligne['id_etudiant_parcours'], 'id_matiere' => $programme->id_matiere];
                $valeur = $ligne['note'] ?? null;

                // 0 est une note valide : seule une valeur vide efface la note
                if ($valeur === null) {
                    Note::where($cle)->delete();
                } else {
                    Note::updateOrCreate($cle, ['note' => $valeur]);
                }
            }
        });

        Log::info('Notes saisies par un enseignant', [
            'compte'  => $request->user()->id,
            'matiere' => $programme->id_matiere,
            'annee'   => $programme->id_annee_aca,
            'lignes'  => count($validated['notes']),
        ]);

        return response()->json(['message' => 'Notes enregistrées.']);
    }

    /** GET /api/enseignant/lecons?annee= : ses leçons de l'année, publiées ou non */
    public function lecons(Request $request)
    {
        $enseignant = $this->enseignant($request);
        $annee = $this->anneeDemandee($request);

        return Lecon::with('matiere:id,matiere,code_matiere')
            ->where('id_annee_aca', $annee)
            ->whereIn('id_matiere', ProgrammeMatiere::where('id_annee_aca', $annee)->where('id_enseignant', $enseignant->id)->select('id_matiere'))
            ->orderByDesc('updated_at')
            ->get();
    }

    /** POST /api/enseignant/lecons */
    public function creerLecon(Request $request)
    {
        $validated = $request->validate($this->reglesLecon() + [
            'id_annee_aca' => ['required', 'integer'],
            'id_matiere'   => ['required', 'integer'],
        ], $this->messagesLecon());

        $programme = $this->programme($request, (int) $validated['id_matiere'], (int) $validated['id_annee_aca']);
        $this->exigerContenu($request, null);

        $lecon = Lecon::create([
            'id_annee_aca'  => $programme->id_annee_aca,
            'id_matiere'    => $programme->id_matiere,
            'id_enseignant' => $programme->id_enseignant,
            'titre'         => $validated['titre'],
            'contenu'       => $validated['contenu'] ?? null,
            'publie_le'     => $request->boolean('publier') ? now() : null,
        ] + $this->fichierEnvoye($request));

        return response()->json($lecon->load('matiere:id,matiere,code_matiere'), 201);
    }

    /** POST /api/enseignant/lecons/{id} (POST : un fichier ne passe pas par PUT en multipart) */
    public function modifierLecon(Request $request, $id)
    {
        $lecon = Lecon::findOrFail($id);
        $this->programme($request, $lecon->id_matiere, $lecon->id_annee_aca);

        $validated = $request->validate($this->reglesLecon(), $this->messagesLecon());
        $retirer = $request->boolean('supprimer_fichier') && !$request->hasFile('fichier');
        $this->exigerContenu($request, $retirer ? null : $lecon->fichier);

        $champs = [
            'titre'     => $validated['titre'],
            'contenu'   => $validated['contenu'] ?? null,
            // Republier ne change pas la date de la première publication
            'publie_le' => $request->boolean('publier') ? ($lecon->publie_le ?? now()) : null,
        ];

        if ($request->hasFile('fichier') || $retirer) {
            $this->supprimerFichier($lecon);
            $champs += $this->fichierEnvoye($request) ?: ['fichier' => null, 'nom_fichier' => null];
        }

        $lecon->update($champs);

        return response()->json($lecon->load('matiere:id,matiere,code_matiere'));
    }

    /** DELETE /api/enseignant/lecons/{id} */
    public function supprimerLecon(Request $request, $id)
    {
        $lecon = Lecon::findOrFail($id);
        $this->programme($request, $lecon->id_matiere, $lecon->id_annee_aca);

        $this->supprimerFichier($lecon);
        $lecon->delete();

        return response()->json(['message' => 'Leçon supprimée.']);
    }

    private function enseignant(Request $request): Enseignant
    {
        $enseignant = $request->user()->enseignant;
        abort_if(!$enseignant, 404, "Aucune fiche enseignant n'est rattachée à ce compte.");

        return $enseignant;
    }

    private function anneeDemandee(Request $request): int
    {
        return (int) $request->validate(['annee' => ['required', 'integer']])['annee'];
    }

    /** Ligne de programme de la matière, seulement si elle est attribuée à l'enseignant connecté pour cette année */
    private function programme(Request $request, int $matiereId, int $annee): ProgrammeMatiere
    {
        $programme = ProgrammeMatiere::with(['matiere.niveau', 'matiere.semestre'])
            ->where('id_annee_aca', $annee)
            ->where('id_matiere', $matiereId)
            ->where('id_enseignant', $this->enseignant($request)->id)
            ->first();

        abort_if(!$programme, 403, "Cette matière ne vous est pas attribuée pour cette année.");

        return $programme;
    }

    private function inscrits(ProgrammeMatiere $programme)
    {
        return EtudiantParcours::actifs()
            ->with('etudiant:id,nom_prenom,matricule')
            ->where('id_annee_aca', $programme->id_annee_aca)
            ->where('id_niveau', $programme->matiere->id_niveau)
            ->get();
    }

    private function verrouillee(ProgrammeMatiere $programme): bool
    {
        return PublicationNote::where('id_annee_aca', $programme->id_annee_aca)
            ->where('id_niveau', $programme->matiere->id_niveau)
            ->where('id_semestre', $programme->matiere->id_semestre)
            ->exists();
    }

    private function resumeMatiere(ProgrammeMatiere $p): array
    {
        return [
            'id'          => $p->id_matiere,
            'matiere'     => $p->matiere->matiere,
            'code'        => $p->matiere->code_matiere,
            'niveau'      => $p->matiere->niveau?->niveau,
            'semestre'    => $p->matiere->semestre?->semestre,
            'coefficient' => $p->coefficient,
            'heures'      => $p->heures,
        ];
    }

    private function reglesLecon(): array
    {
        return [
            'titre'   => ['required', 'string', 'max:255'],
            'contenu' => ['nullable', 'string', 'max:50000'],
            'fichier' => ['nullable', 'file', 'mimes:pdf,doc,docx,ppt,pptx,xlsx,zip,jpg,jpeg,png', 'max:10240'],
            'publier' => ['nullable', 'boolean'],
            'supprimer_fichier' => ['nullable', 'boolean'],
        ];
    }

    private function messagesLecon(): array
    {
        return [
            'titre.required' => 'Le titre de la leçon est obligatoire.',
            'fichier.mimes'  => 'Formats acceptés : PDF, Word, PowerPoint, Excel, ZIP, JPG, PNG.',
            'fichier.max'    => 'Le fichier ne doit pas dépasser 10 Mo.',
            'fichier.uploaded' => "Le fichier n'a pas pu être reçu : le serveur accepte au plus " . ini_get('upload_max_filesize') . 'o par fichier.',
        ];
    }

    /** Une leçon vide n'aurait rien à montrer aux étudiants */
    private function exigerContenu(Request $request, ?string $fichierConserve): void
    {
        if (!$request->filled('contenu') && !$request->hasFile('fichier') && !$fichierConserve) {
            abort(response()->json([
                'message' => 'Ajoutez un texte ou un fichier.',
                'errors'  => ['contenu' => ['Ajoutez un texte ou un fichier.']],
            ], 422));
        }
    }

    /** Fichier reçu : même rangement que les annonces, chemin public dans la base */
    private function fichierEnvoye(Request $request): array
    {
        if (!$request->hasFile('fichier')) {
            return [];
        }

        return [
            'fichier'     => '/storage/' . $request->file('fichier')->store('lecons', 'public'),
            'nom_fichier' => $request->file('fichier')->getClientOriginalName(),
        ];
    }

    private function supprimerFichier(Lecon $lecon): void
    {
        if ($lecon->fichier) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $lecon->fichier));
        }
    }
}
