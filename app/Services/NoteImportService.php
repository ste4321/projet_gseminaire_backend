<?php

namespace App\Services;

use App\Models\AnneeAca;
use App\Models\Matiere;
use App\Models\Niveau;
use App\Models\Semestre;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Lecture d'un fichier de notes, commune à l'import de l'administration et à celui de l'enseignant.
 * Nom de fichier attendu : NOTES_<CODE_MATIERE>_<NIVEAU>_<SEMESTRE>_<ANNEE>.xlsx
 *   ex : NOTES_FRS_L1_S1_2025-2026.xlsx
 * Le niveau et le semestre doivent être ceux de la matière : un fichier préparé pour le
 * mauvais semestre est refusé au lieu d'écrire des notes au mauvais endroit.
 * Colonnes obligatoires (ligne 1) : matricule, note
 *
 * Chaque erreur bloquante interrompt la requête par une réponse 422 au message lisible.
 */
class NoteImportService
{
    // CODE : tout sauf « _ » (séparateur) ; NIVEAU : L1, 1, M2… ; SEMESTRE : S1, S2…
    private const FORMAT_NOM = '/^NOTES_([^_]+)_(?:L)?([A-Za-z]?[0-9])_(S[0-9]+)_([0-9]{4}-[0-9]{4})\.(xlsx|xls|csv)$/i';
    private const ANCIEN_FORMAT = '/^NOTES_[^_]+_[A-Za-z]?[0-9]_[0-9]{4}-[0-9]{4}\.(xlsx|xls|csv)$/i';

    public const REGLES_FICHIER = ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'];
    public const MESSAGES_FICHIER = [
        'file.required' => 'Veuillez choisir un fichier.',
        'file.mimes'    => 'Le fichier doit être au format Excel (.xlsx, .xls) ou CSV.',
        'file.max'      => 'Le fichier ne doit pas dépasser 10 Mo.',
    ];

    /**
     * Matière, niveau, semestre et année désignés par le nom du fichier.
     * @return array{matiere: Matiere, niveau: Niveau, semestre: Semestre, annee: AnneeAca, code: string}
     */
    public function cible(string $nomFichier): array
    {
        if (!preg_match(self::FORMAT_NOM, $nomFichier, $m)) {
            $message = 'Nom de fichier invalide. Format attendu : NOTES_<CODE_MATIERE>_<NIVEAU>_<SEMESTRE>_<ANNÉE>.xlsx (ex : NOTES_FRS_L1_S1_2025-2026.xlsx).';
            if (preg_match(self::ANCIEN_FORMAT, $nomFichier)) {
                $message .= ' Le semestre (S1, S2…) est désormais obligatoire dans le nom.';
            }
            $this->refuser($message);
        }

        [, $codeMatiere, $niveauCode, $semestreCode, $anneeLabel] = $m;
        $niveauLibelle = ctype_digit($niveauCode) ? 'L' . $niveauCode : strtoupper($niveauCode);
        $semestreCode = strtoupper($semestreCode);

        $niveau   = Niveau::where('niveau', $niveauLibelle)->first();
        $semestre = Semestre::whereRaw('UPPER(code_semestre) = ?', [$semestreCode])->first();
        $annee    = AnneeAca::where('annee_aca', $anneeLabel)->first();

        $manquants = array_keys(array_filter([
            "niveau {$niveauLibelle}"         => !$niveau,
            "semestre {$semestreCode}"        => !$semestre,
            "année académique {$anneeLabel}"  => !$annee,
        ]));
        if ($manquants) {
            $this->refuser('Introuvable dans la base : ' . implode(', ', $manquants) . '.');
        }

        // Un même code peut exister à plusieurs niveaux / semestres : le nom du fichier les départage
        $matiere = Matiere::where('code_matiere', $codeMatiere)
            ->where('id_niveau', $niveau->id)
            ->where('id_semestre', $semestre->id)
            ->first();

        if (!$matiere) {
            $ailleurs = Matiere::with(['niveau', 'semestre'])->where('code_matiere', $codeMatiere)->get()
                ->map(fn ($x) => trim(($x->niveau?->niveau ?? '?') . ' · ' . ($x->semestre?->code_semestre ?? 'sans semestre')))
                ->unique()->implode(', ');

            $this->refuser($ailleurs
                ? "Aucune matière {$codeMatiere} en {$niveauLibelle} · {$semestreCode}. Ce code existe en : {$ailleurs}."
                : "Aucune matière n'a le code {$codeMatiere}.");
        }

        return ['matiere' => $matiere, 'niveau' => $niveau, 'semestre' => $semestre, 'annee' => $annee, 'code' => $codeMatiere];
    }

    /**
     * Lignes du fichier : notes valides d'un côté, lignes refusées (avec leur numéro Excel) de l'autre.
     * @return array{lignes: array<int, array{ligne: int, matricule: string, note: float}>, erreurs: string[]}
     */
    public function lignes(UploadedFile $file): array
    {
        try {
            $data = Excel::toArray([], $file)[0] ?? [];
        } catch (\Throwable $e) {
            $this->refuser('Impossible de lire le fichier.');
        }

        if (count($data) < 2) {
            $this->refuser('Le fichier ne contient aucune note.');
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $data[0]);
        $matriculeIndex = array_search('matricule', $header, true);
        $noteIndex = array_search('note', $header, true);
        if ($matriculeIndex === false || $noteIndex === false) {
            $this->refuser('Le fichier doit contenir les colonnes « matricule » et « note » en première ligne.');
        }

        $lignes = [];
        $erreurs = [];
        for ($i = 1; $i < count($data); $i++) {
            $ligne = $i + 1; // numéro de ligne tel qu'affiché dans Excel
            $row = $data[$i];
            $matricule = trim((string) ($row[$matriculeIndex] ?? ''));
            $noteValue = str_replace(',', '.', trim((string) ($row[$noteIndex] ?? '')));

            if ($matricule === '' && $noteValue === '') {
                continue; // ligne vide
            }
            if ($matricule === '' || !is_numeric($noteValue)) {
                $erreurs[] = "Ligne {$ligne} : matricule ou note manquant(e) / invalide";
                continue;
            }
            $noteValue = (float) $noteValue;
            if ($noteValue < 0 || $noteValue > 20) {
                $erreurs[] = "Ligne {$ligne} : note {$noteValue} hors de l'intervalle 0 – 20";
                continue;
            }

            $lignes[] = ['ligne' => $ligne, 'matricule' => $matricule, 'note' => $noteValue];
        }

        return ['lignes' => $lignes, 'erreurs' => $erreurs];
    }

    private function refuser(string $message): never
    {
        abort(response()->json(['message' => $message], 422));
    }
}
