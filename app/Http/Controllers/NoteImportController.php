<?php

namespace App\Http\Controllers;

use App\Models\AnneeAca;
use App\Models\Etudiant;
use App\Models\EtudiantParcours;
use App\Models\Matiere;
use App\Models\Niveau;
use App\Models\Note;
use App\Models\Semestre;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Import Excel des notes d'une matière.
 * Nom de fichier attendu : NOTES_<CODE_MATIERE>_<NIVEAU>_<SEMESTRE>_<ANNEE>.xlsx
 *   ex : NOTES_FRS_L1_S1_2025-2026.xlsx
 * Le niveau et le semestre doivent être ceux de la matière : un fichier préparé pour le
 * mauvais semestre est refusé au lieu d'écrire des notes au mauvais endroit.
 * La matière doit être au programme de l'année du fichier.
 * Colonnes obligatoires (ligne 1) : matricule, note
 */
class NoteImportController extends Controller
{
    // CODE : tout sauf « _ » (séparateur) ; NIVEAU : L1, 1, M2… ; SEMESTRE : S1, S2…
    private const FORMAT_NOM = '/^NOTES_([^_]+)_(?:L)?([A-Za-z]?[0-9])_(S[0-9]+)_([0-9]{4}-[0-9]{4})\.(xlsx|xls|csv)$/i';
    private const ANCIEN_FORMAT = '/^NOTES_[^_]+_[A-Za-z]?[0-9]_[0-9]{4}-[0-9]{4}\.(xlsx|xls|csv)$/i';

    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ], [
            'file.required' => 'Veuillez choisir un fichier.',
            'file.mimes'    => 'Le fichier doit être au format Excel (.xlsx, .xls) ou CSV.',
            'file.max'      => 'Le fichier ne doit pas dépasser 10 Mo.',
        ]);

        $file = $request->file('file');

        $nomFichier = $file->getClientOriginalName();
        if (!preg_match(self::FORMAT_NOM, $nomFichier, $m)) {
            $message = 'Nom de fichier invalide. Format attendu : NOTES_<CODE_MATIERE>_<NIVEAU>_<SEMESTRE>_<ANNÉE>.xlsx (ex : NOTES_FRS_L1_S1_2025-2026.xlsx).';
            if (preg_match(self::ANCIEN_FORMAT, $nomFichier)) {
                $message .= ' Le semestre (S1, S2…) est désormais obligatoire dans le nom.';
            }
            return response()->json(['message' => $message], 422);
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
            return response()->json(['message' => 'Introuvable dans la base : ' . implode(', ', $manquants) . '.'], 422);
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

            return response()->json([
                'message' => $ailleurs
                    ? "Aucune matière {$codeMatiere} en {$niveauLibelle} · {$semestreCode}. Ce code existe en : {$ailleurs}."
                    : "Aucune matière n'a le code {$codeMatiere}.",
            ], 422);
        }

        // La matière doit être enseignée l'année du fichier : sinon ses notes n'apparaîtraient dans aucun relevé
        if (!$matiere->programmes()->where('id_annee_aca', $annee->id)->exists()) {
            return response()->json([
                'message' => "{$matiere->matiere} ({$codeMatiere}) n'est pas au programme {$niveauLibelle} de {$anneeLabel}. "
                    . "Ajoutez-la au programme de cette année dans le module Matières, puis relancez l'import.",
            ], 422);
        }

        try {
            $data = Excel::toArray([], $file)[0] ?? [];
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Impossible de lire le fichier.'], 422);
        }

        if (count($data) < 2) {
            return response()->json(['message' => 'Le fichier ne contient aucune note.'], 422);
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $data[0]);
        $matriculeIndex = array_search('matricule', $header, true);
        $noteIndex = array_search('note', $header, true);
        if ($matriculeIndex === false || $noteIndex === false) {
            return response()->json(['message' => 'Le fichier doit contenir les colonnes « matricule » et « note » en première ligne.'], 422);
        }

        $inserted = 0;
        $errors = [];

        DB::transaction(function () use ($data, $matriculeIndex, $noteIndex, $niveau, $annee, $matiere, $niveauLibelle, $anneeLabel, &$inserted, &$errors) {
            for ($i = 1; $i < count($data); $i++) {
                $ligne = $i + 1; // numéro de ligne tel qu'affiché dans Excel
                $row = $data[$i];
                $matricule = trim((string) ($row[$matriculeIndex] ?? ''));
                $noteValue = str_replace(',', '.', trim((string) ($row[$noteIndex] ?? '')));

                if ($matricule === '' && $noteValue === '') {
                    continue; // ligne vide
                }
                if ($matricule === '' || !is_numeric($noteValue)) {
                    $errors[] = "Ligne {$ligne} : matricule ou note manquant(e) / invalide";
                    continue;
                }
                $noteValue = (float) $noteValue;
                if ($noteValue < 0 || $noteValue > 20) {
                    $errors[] = "Ligne {$ligne} : note {$noteValue} hors de l'intervalle 0 – 20";
                    continue;
                }

                $etudiant = Etudiant::where('matricule', $matricule)->first();
                if (!$etudiant) {
                    $errors[] = "Ligne {$ligne} : étudiant {$matricule} introuvable";
                    continue;
                }

                $parcours = EtudiantParcours::where('id_etudiant', $etudiant->id)
                    ->where('id_niveau', $niveau->id)
                    ->where('id_annee_aca', $annee->id)
                    ->first();
                if (!$parcours) {
                    $errors[] = "Ligne {$ligne} : {$matricule} n'est pas inscrit en {$niveauLibelle} pour {$anneeLabel}";
                    continue;
                }

                Note::updateOrCreate(
                    ['id_etudiant_parcours' => $parcours->id, 'id_matiere' => $matiere->id],
                    ['note' => $noteValue, 'statut' => '']
                );
                $inserted++;
            }
        });

        return response()->json([
            'message' => "{$inserted} note(s) importée(s) avec succès en {$matiere->matiere} ({$niveauLibelle} · {$semestreCode} · {$anneeLabel}).",
            'imported' => $inserted,
            'errors' => $errors,
        ]);
    }
}
