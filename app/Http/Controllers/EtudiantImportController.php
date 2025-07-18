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
        try {
            $file = $request->file('file');
            $filename = $file->getClientOriginalName(); // Etudiant_L1_2025-2026.xlsx

            // Extraire niveau et année depuis le nom de fichier
            preg_match('/Etudiant_(L[1-3])_([0-9]{4}-[0-9]{4})/', $filename, $matches);
            if (count($matches) !== 3) {
                return response()->json(['message' => 'Nom de fichier invalide. Format attendu : Etudiant_LX_YYYY-YYYY.xlsx'], 400);
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

            DB::beginTransaction();

            foreach (array_slice($rows, 1) as $index => $row) {
                $nomPrenom = trim($row['A'] ?? '');
                $matricule = trim($row['B'] ?? '');
                $diocese = trim($row['C'] ?? '');
                $email = trim($row['D'] ?? '');
                $telephone = trim($row['E'] ?? '');

                // Nettoyage de caractères invisibles
                $matricule = preg_replace('/[\x00-\x1F\x7F\xA0]/u', '', $matricule);

                if (empty($matricule)) {
                    Log::warning("Ligne " . ($index + 2) . " ignorée : matricule vide ou invalide.");
                    continue;
                }

                Log::info("Import étudiant ligne " . ($index + 2), [
                    'nom_prenom' => $nomPrenom,
                    'matricule' => $matricule,
                    'email' => $email,
                    'telephone' => $telephone,
                    'diocese' => $diocese,
                    'niveau' => $niveauCode,
                    'annee_aca' => $anneeLabel
                ]);

                // Création ou mise à jour
                $etudiant = Etudiant::updateOrCreate(
                    ['matricule' => $matricule],
                    [
                        'nom_prenom' => $nomPrenom,
                        'email' => $email,
                        'telephone' => $telephone,
                        'diocese' => $diocese
                    ]
                );

                // Lien avec parcours s'il n'existe pas encore
                EtudiantParcours::firstOrCreate([
                    'id_etudiant' => $etudiant->id,
                    'id_annee_aca' => $annee->id,
                    'id_niveau' => $niveau->id,
                ], [
                    'statut' => 'inscrit',
                    'date_inscription' => now(),
                    'matricule' => $etudiant->matricule,
                ]);
            }

            DB::commit();

            return response()->json(['message' => 'Importation réussie.']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Erreur lors de l'import : " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'message' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }
}
