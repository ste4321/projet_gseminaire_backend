<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Etudiant;
use App\Models\AnneeAca;
use App\Models\Niveau;
use App\Models\EtudiantParcours;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class EtudiantImportController extends Controller
{
    public function import(Request $request)
    {
        // Augmenter les limites pour les gros imports
        ini_set('max_execution_time', 300); // 5 minutes
        ini_set('memory_limit', '512M');
        
        try {
            $file = $request->file('file');
            $filename = $file->getClientOriginalName();

            // Extraire niveau et année depuis le nom de fichier
            preg_match('/Etudiant_(L[1-3])_([0-9]{4}-[0-9]{4})/', $filename, $matches);
            if (count($matches) !== 3) {
                return response()->json([
                    'message' => 'Nom de fichier invalide. Format attendu : Etudiant_LX_YYYY-YYYY.xlsx'
                ], 400);
            }
            
            $niveauCode = $matches[1];
            $anneeLabel = $matches[2];

            // Lire le fichier Excel
            $spreadsheet = IOFactory::load($file->getPathname());
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);

            // Obtenir ou créer l'année académique
            $annee = AnneeAca::firstOrCreate(['annee_aca' => $anneeLabel]);

            // Obtenir l'ID du niveau
            $niveau = Niveau::where('niveau', $niveauCode)->firstOrFail();

            $totalRows = count($rows) - 1; // -1 pour exclure l'en-tête
            $processedRows = 0;
            $errorRows = [];

            DB::beginTransaction();

            // Traitement par batch pour optimiser les performances
            $batchSize = 50;
            $chunks = array_chunk(array_slice($rows, 1), $batchSize);

            foreach ($chunks as $chunkIndex => $chunk) {
                $etudiants = [];
                $parcours = [];

                foreach ($chunk as $index => $row) {
                    $globalIndex = ($chunkIndex * $batchSize) + $index + 1;
                    
                    $nomPrenom = trim($row['A'] ?? '');
                    $matricule = trim($row['B'] ?? '');
                    $diocese = trim($row['C'] ?? '');
                    $email = trim($row['D'] ?? '');
                    $telephone = trim($row['E'] ?? '');

                    // Nettoyage de caractères invisibles
                    $matricule = preg_replace('/[\x00-\x1F\x7F\xA0]/u', '', $matricule);

                    if (empty($matricule) || empty($nomPrenom)) {
                        $errorRows[] = "Ligne " . ($globalIndex + 1) . " : matricule ou nom manquant";
                        continue;
                    }

                    // Vérifier si l'étudiant existe déjà
                    $existingEtudiant = Etudiant::where('matricule', $matricule)->first();
                    
                    if ($existingEtudiant) {
                        // Mettre à jour les infos existantes
                        $existingEtudiant->update([
                            'nom_prenom' => $nomPrenom,
                            'email' => $email,
                            'telephone' => $telephone,
                            'diocese' => $diocese
                        ]);
                        $etudiantId = $existingEtudiant->id;
                    } else {
                        // Créer un nouvel étudiant
                        $etudiant = Etudiant::create([
                            'matricule' => $matricule,
                            'nom_prenom' => $nomPrenom,
                            'email' => $email,
                            'telephone' => $telephone,
                            'diocese' => $diocese
                        ]);
                        $etudiantId = $etudiant->id;
                    }

                    // Vérifier si le parcours existe déjà
                    $existingParcours = EtudiantParcours::where([
                        'id_etudiant' => $etudiantId,
                        'id_annee_aca' => $annee->id,
                        'id_niveau' => $niveau->id,
                    ])->first();

                    if (!$existingParcours) {
                        EtudiantParcours::create([
                            'id_etudiant' => $etudiantId,
                            'id_annee_aca' => $annee->id,
                            'id_niveau' => $niveau->id,
                            'statut' => 'inscrit',
                            'date_inscription' => now(),
                            'matricule' => $matricule,
                        ]);
                    }

                    $processedRows++;
                }

                // Log du progrès
                Log::info("Batch " . ($chunkIndex + 1) . " traité : {$processedRows}/{$totalRows} étudiants");
            }

            DB::commit();

            $response = [
                'message' => 'Importation réussie.',
                'total_rows' => $totalRows,
                'processed_rows' => $processedRows,
                'success_rate' => round(($processedRows / $totalRows) * 100, 2) . '%'
            ];

            if (!empty($errorRows)) {
                $response['errors'] = $errorRows;
                $response['error_count'] = count($errorRows);
            }

            return response()->json($response);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Erreur lors de l'import : " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'message' => 'Erreur lors de l\'importation : ' . $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }
}