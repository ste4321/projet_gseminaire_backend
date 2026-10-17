<?php

namespace App\Http\Controllers;

use App\Models\AnneeAca;
use App\Models\Etudiant;
use App\Models\EtudiantParcours;
use App\Models\Matiere;
use App\Models\Niveau;
use App\Models\Note;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Import Excel des notes d'une matière.
 * Nom de fichier attendu : NOTES_<CODE_MATIERE>_<NIVEAU>_<ANNEE>.xlsx (ex : NOTES_FRS_L1_2025-2026.xlsx)
 * Colonnes obligatoires (ligne 1) : matricule, note
 */
class NoteImportController extends Controller
{
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

        if (!preg_match('/^NOTES_([A-Za-z0-9]+)_(?:L)?([A-Za-z]?[0-9])_([0-9]{4}-[0-9]{4})\.(xlsx|xls|csv)$/i', $file->getClientOriginalName(), $m)) {
            return response()->json([
                'message' => 'Nom de fichier invalide. Format attendu : NOTES_<CODE_MATIERE>_L1_2025-2026.xlsx',
            ], 422);
        }

        [, $codeMatiere, $niveauCode, $anneeLabel] = $m;
        $niveauLibelle = ctype_digit($niveauCode) ? 'L' . $niveauCode : strtoupper($niveauCode);

        $matiere = Matiere::where('code_matiere', $codeMatiere)->first();
        $niveau  = Niveau::where('niveau', $niveauLibelle)->first();
        $annee   = AnneeAca::where('annee_aca', $anneeLabel)->first();

        $manquants = array_keys(array_filter([
            "matière {$codeMatiere}"          => !$matiere,
            "niveau {$niveauLibelle}"         => !$niveau,
            "année académique {$anneeLabel}"  => !$annee,
        ]));
        if ($manquants) {
            return response()->json(['message' => 'Introuvable dans la base : ' . implode(', ', $manquants) . '.'], 422);
        }

        if ((int) $matiere->id_niveau !== (int) $niveau->id) {
            return response()->json([
                'message' => "La matière {$codeMatiere} n'appartient pas au niveau {$niveauLibelle}.",
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
            'message' => "{$inserted} note(s) importée(s) avec succès.",
            'imported' => $inserted,
            'errors' => $errors,
        ]);
    }
}
