<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Matiere;
use App\Models\Niveau;
use App\Models\AnneeAca;
use App\Models\Etudiant;
use App\Models\EtudiantParcours;
use App\Models\Note;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Log;

class NoteImportController extends Controller
{
    public function import(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Fichier invalide', 'errors' => $validator->errors()], 422);
        }

        $file = $request->file('file');
        $filename = $file->getClientOriginalName();

        // Exemple attendu : NOTES_FRS_L1_2022-2023.xlsx
        if (!preg_match('/^NOTES_([A-Z0-9]+)_L([1-3])_([0-9]{4}-[0-9]{4})\.xlsx$/', $filename, $matches)) {
            return response()->json(['message' => 'Le nom du fichier doit suivre le format : NOTES_<CODE_MATIERE>_L<NIVEAU>_<ANNEE>.xlsx'], 400);
        }

        [$fullMatch, $codeMatiere, $niveauNum, $anneeAcaStr] = $matches;

        // Récupérer les IDs correspondants
        $matiere = Matiere::where('code_matiere', $codeMatiere)->first();
        $niveau = Niveau::where('niveau', 'L' . $niveauNum)->first();
        $annee = AnneeAca::where('annee_aca', $anneeAcaStr)->first();

        if (!$matiere || !$niveau || !$annee) {
            return response()->json([
                'message' => 'Matière, niveau ou année académique introuvable dans la base de données.',
                'details' => compact('codeMatiere', 'niveauNum', 'anneeAcaStr')
            ], 404);
        }

        try {
            $data = Excel::toArray([], $file)[0]; // Première feuille uniquement

            if (count($data) < 2) {
                return response()->json(['message' => 'Le fichier semble vide ou ne contient pas de données valides.'], 400);
            }

            $header = array_map('strtolower', $data[0]);
            if (!in_array('note', $header) || !in_array('matricule', $header)) {
                return response()->json(['message' => 'Le fichier doit contenir les colonnes : note, matricule'], 400);
            }

            $matriculeIndex = array_search('matricule', $header);
            $noteIndex = array_search('note', $header);

            $inserted = 0;
            $errors = [];

            for ($i = 1; $i < count($data); $i++) {
                $row = $data[$i];
                $matricule = trim($row[$matriculeIndex] ?? '');
                $noteValue = str_replace(',', '.', trim($row[$noteIndex] ?? ''));

                if (!$matricule || !is_numeric($noteValue)) {
                    $errors[] = "Ligne $i : données manquantes ou invalides (matricule ou note)";
                    continue;
                }

                $etudiant = Etudiant::where('matricule', $matricule)->first();
                if (!$etudiant) {
                    $errors[] = "Ligne $i : étudiant avec matricule [$matricule] non trouvé";
                    continue;
                }

                $parcours = EtudiantParcours::where([
                    ['id_etudiant', '=', $etudiant->id],
                    ['id_niveau', '=', $niveau->id],
                    ['id_annee_aca', '=', $annee->id]
                ])->first();

                if (!$parcours) {
                    $errors[] = "Ligne $i : aucun parcours trouvé pour [$matricule] en L$niveauNum - $anneeAcaStr";
                    continue;
                }

                // Mise à jour ou insertion
                $note = Note::updateOrCreate(
                    [
                        'id_etudiant_parcours' => $parcours->id,
                        'id_matiere' => $matiere->id
                    ],
                    [
                        'note' => $noteValue,
                        'statut' => '',
                    ]
                );

                $inserted++;
            }

            return response()->json([
                'message' => "$inserted note(s) importée(s) avec succès.",
                'errors' => $errors
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur import notes : ' . $e->getMessage());
            return response()->json(['message' => 'Erreur lors du traitement du fichier', 'error' => $e->getMessage()], 500);
        }
    }
}
