<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Etudiant;
use App\Models\AnneeAca;
use App\Models\Niveau;
use App\Models\EtudiantParcours;
use App\Models\Password;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\Hash;

class EtudiantImportController extends Controller
{
    public function import(Request $request)
    {
        ini_set('max_execution_time', 300);
        ini_set('memory_limit', '512M');

        try {
            $file = $request->file('file');
            $filename = $file->getClientOriginalName();

            preg_match('/Etudiant_(L[1-3])_([0-9]{4}-[0-9]{4})/', $filename, $matches);
            if (count($matches) !== 3) {
                return response()->json([
                    'message' => 'Nom de fichier invalide. Format attendu : Etudiant_LX_YYYY-YYYY.xlsx'
                ], 400);
            }

            $niveauCode = $matches[1];
            $anneeLabel = $matches[2];

            $spreadsheet = IOFactory::load($file->getPathname());
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);

            $annee = AnneeAca::firstOrCreate(['annee_aca' => $anneeLabel]);
            $niveau = Niveau::where('niveau', $niveauCode)->firstOrFail();

            $totalRows = count($rows) - 1;
            $processedRows = 0;
            $errorRows = [];

            DB::beginTransaction();

            $batchSize = 50;
            $chunks = array_chunk(array_slice($rows, 1), $batchSize);

            foreach ($chunks as $chunkIndex => $chunk) {
                foreach ($chunk as $index => $row) {
                    $globalIndex = ($chunkIndex * $batchSize) + $index + 1;

                    $nomPrenom = trim($row['A'] ?? '');
                    $matricule = trim($row['B'] ?? '');
                    $diocese = trim($row['C'] ?? '');
                    $email = trim($row['D'] ?? '');
                    $telephone = trim($row['E'] ?? '');

                    $matricule = preg_replace('/[\x00-\x1F\x7F\xA0]/u', '', $matricule);

                    if (empty($matricule) || empty($nomPrenom)) {
                        $errorRows[] = "Ligne " . ($globalIndex + 1) . " : matricule ou nom manquant";
                        continue;
                    }

                    $existingEtudiant = Etudiant::where('matricule', $matricule)->first();
                    $etudiantId = null;

                    if ($existingEtudiant) {
                        $existingEtudiant->update([
                            'nom_prenom' => $nomPrenom,
                            'email' => $email,
                            'telephone' => $telephone,
                            'diocese' => $diocese
                        ]);
                        $etudiantId = $existingEtudiant->id;

                        // Vérifier s'il a un user_cession lié, sinon créer
                        if (!$existingEtudiant->id_user_cession) {
                            $password = Str::random(6);
                            $hashedPassword = Hash::make($password);
                            $user = User::create([
                                'email' => $email,
                                'password' => bcrypt($password),
                                'is_verified' => false,
                                'role' => 'etudiant'
                            ]);

                            Password::create([
                                'id_user_cession' => $user->id,
                                'password' => $password,
                                'psw_scrypt' => $hashedPassword
                            ]);

                            $existingEtudiant->update([
                                'id_user_cession' => $user->id
                            ]);
                        }

                    } else {
                        // Créer user_cession
                        $password = Str::random(6);
                        $user = User::create([
                            'email' => $email,
                            'password' => bcrypt($password),
                            'is_verified' => false,
                            'role' => 'etudiant'
                        ]);

                        Password::create([
                            'id_user_cession' => $user->id,
                            'password' => $password,
                            'psw_scrypt' => $password
                        ]);

                        // Créer l'étudiant
                        $etudiant = Etudiant::create([
                            'matricule' => $matricule,
                            'nom_prenom' => $nomPrenom,
                            'email' => $email,
                            'telephone' => $telephone,
                            'diocese' => $diocese,
                            'id_user_cession' => $user->id
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