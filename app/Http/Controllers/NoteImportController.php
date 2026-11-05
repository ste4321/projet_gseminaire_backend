<?php

namespace App\Http\Controllers;

use App\Models\Etudiant;
use App\Models\EtudiantParcours;
use App\Models\Note;
use App\Services\NoteImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Import Excel des notes d'une matière (administration : toutes les matières).
 * Format du fichier et contrôles communs : voir NoteImportService.
 * La matière doit être au programme de l'année du fichier.
 */
class NoteImportController extends Controller
{
    public function import(Request $request, NoteImportService $import)
    {
        $request->validate(['file' => NoteImportService::REGLES_FICHIER], NoteImportService::MESSAGES_FICHIER);

        $file = $request->file('file');
        ['matiere' => $matiere, 'niveau' => $niveau, 'semestre' => $semestre, 'annee' => $annee, 'code' => $codeMatiere]
            = $import->cible($file->getClientOriginalName());
        $niveauLibelle = $niveau->niveau;
        $semestreCode = strtoupper($semestre->code_semestre);
        $anneeLabel = $annee->annee_aca;

        // La matière doit être enseignée l'année du fichier : sinon ses notes n'apparaîtraient dans aucun relevé
        if (!$matiere->programmes()->where('id_annee_aca', $annee->id)->exists()) {
            return response()->json([
                'message' => "{$matiere->matiere} ({$codeMatiere}) n'est pas au programme {$niveauLibelle} de {$anneeLabel}. "
                    . "Ajoutez-la au programme de cette année dans le module Matières, puis relancez l'import.",
            ], 422);
        }

        ['lignes' => $lignes, 'erreurs' => $errors] = $import->lignes($file);
        $inserted = 0;

        DB::transaction(function () use ($lignes, $niveau, $annee, $matiere, $niveauLibelle, $anneeLabel, &$inserted, &$errors) {
            foreach ($lignes as ['ligne' => $ligne, 'matricule' => $matricule, 'note' => $noteValue]) {
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

        // Erreurs dans l'ordre des lignes du fichier (lecture puis contrôle des étudiants)
        $numero = fn (string $e) => preg_match('/^Ligne (\d+)/', $e, $m) ? (int) $m[1] : 0;
        usort($errors, fn ($a, $b) => $numero($a) <=> $numero($b));

        return response()->json([
            'message' => "{$inserted} note(s) importée(s) avec succès en {$matiere->matiere} ({$niveauLibelle} · {$semestreCode} · {$anneeLabel}).",
            'imported' => $inserted,
            'errors' => $errors,
        ]);
    }
}
