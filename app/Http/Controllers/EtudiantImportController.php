<?php

namespace App\Http\Controllers;

use App\Models\AnneeAca;
use App\Models\Etudiant;
use App\Models\EtudiantParcours;
use App\Models\Niveau;
use App\Services\AccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Import Excel des étudiants.
 * Nom de fichier attendu : Etudiant_<NIVEAU>_<ANNEE>.xlsx (ex : Etudiant_L1_2025-2026.xlsx)
 * Colonnes : A nom et prénom | B matricule | C diocèse | D email | E téléphone (ligne 1 = en-têtes)
 */
class EtudiantImportController extends Controller
{
    public function __construct(private AccountService $accounts)
    {
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ], [
            'file.required' => 'Veuillez choisir un fichier.',
            'file.mimes'    => 'Le fichier doit être au format Excel (.xlsx ou .xls).',
            'file.max'      => 'Le fichier ne doit pas dépasser 10 Mo.',
        ]);

        ini_set('max_execution_time', 300);

        $file = $request->file('file');

        if (!preg_match('/Etudiant_([A-Za-z][0-9])_([0-9]{4}-[0-9]{4})/', $file->getClientOriginalName(), $matches)) {
            return response()->json([
                'message' => 'Nom de fichier invalide. Format attendu : Etudiant_L1_2025-2026.xlsx',
            ], 422);
        }

        $niveau = Niveau::where('niveau', strtoupper($matches[1]))->first();
        if (!$niveau) {
            return response()->json(['message' => "Le niveau {$matches[1]} n'existe pas. Créez-le d'abord dans « Niveaux »."], 422);
        }
        $annee = AnneeAca::firstOrCreate(['annee_aca' => $matches[2]]);

        try {
            $rows = IOFactory::load($file->getPathname())->getActiveSheet()->toArray(null, true, true, true);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Impossible de lire le fichier Excel.'], 422);
        }

        $rows = array_slice($rows, 1); // en-têtes
        $totalRows = 0;
        $processedRows = 0;
        $createdAccounts = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $ligne = $index + 2; // numéro de ligne tel qu'affiché dans Excel

            $nomPrenom = trim((string) ($row['A'] ?? ''));
            $matricule = preg_replace('/[\x00-\x1F\x7F\xA0]/u', '', trim((string) ($row['B'] ?? '')));
            $diocese   = trim((string) ($row['C'] ?? '')) ?: null;
            $email     = strtolower(trim((string) ($row['D'] ?? ''))) ?: null;
            $telephone = trim((string) ($row['E'] ?? '')) ?: null;

            if ($nomPrenom === '' && $matricule === '') {
                continue; // ligne vide
            }
            $totalRows++;

            if ($nomPrenom === '' || $matricule === '') {
                $errors[] = "Ligne {$ligne} : matricule ou nom manquant";
                continue;
            }
            if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Ligne {$ligne} : email invalide ({$email}), importé sans email";
                $email = null;
            }

            try {
                // Une transaction par ligne : une ligne en erreur n'annule pas tout l'import
                DB::transaction(function () use ($matricule, $nomPrenom, $diocese, $email, $telephone, $annee, $niveau, &$createdAccounts) {
                    $etudiant = Etudiant::where('matricule', $matricule)->first();

                    // Email déjà pris par un autre étudiant : on garde l'étudiant, sans cet email
                    if ($email && Etudiant::where('email', $email)->where('matricule', '!=', $matricule)->exists()) {
                        $email = null;
                    }

                    if ($etudiant) {
                        $etudiant->update(array_filter([
                            'nom_prenom' => $nomPrenom,
                            'diocese'    => $diocese,
                            'email'      => $email,
                            'telephone'  => $telephone,
                        ], fn ($v) => $v !== null));
                    } else {
                        $etudiant = Etudiant::create([
                            'matricule'  => $matricule,
                            'nom_prenom' => $nomPrenom,
                            'diocese'    => $diocese,
                            'email'      => $email,
                            'telephone'  => $telephone,
                        ]);
                    }

                    if (!$etudiant->id_user_cession) {
                        [$user] = $this->accounts->create('etudiant', $email, $matricule);
                        $etudiant->update(['id_user_cession' => $user->id]);
                        $createdAccounts++;
                    }

                    EtudiantParcours::firstOrCreate(
                        [
                            'id_etudiant'  => $etudiant->id,
                            'id_annee_aca' => $annee->id,
                        ],
                        [
                            'id_niveau'        => $niveau->id,
                            'statut'           => 'inscrit',
                            'date_inscription' => now(),
                            'matricule'        => $matricule,
                        ]
                    );
                });
                $processedRows++;
            } catch (\Throwable $e) {
                Log::warning("Import étudiants, ligne {$ligne}", ['matricule' => $matricule, 'error' => $e->getMessage()]);
                $errors[] = "Ligne {$ligne} : impossible d'importer le matricule {$matricule}";
            }
        }

        if ($totalRows === 0) {
            return response()->json(['message' => 'Le fichier ne contient aucun étudiant.'], 422);
        }

        return response()->json([
            'message'        => 'Importation terminée.',
            'total_rows'     => $totalRows,
            'processed_rows' => $processedRows,
            'created_accounts' => $createdAccounts,
            'success_rate'   => round(($processedRows / $totalRows) * 100, 2) . '%',
            'errors'         => $errors,
            'error_count'    => count($errors),
        ]);
    }
}
