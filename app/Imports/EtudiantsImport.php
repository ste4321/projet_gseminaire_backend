<?php

namespace App\Imports;

use App\Models\Etudiant;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

class EtudiantsImport implements ToCollection
{
    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            if ($index === 0) continue; // Ignore la ligne d'en-tête

            $matricule = trim($row[1]); // colonne B
            if (!$matricule) continue; // Si matricule vide, ignorer

            Etudiant::updateOrCreate(
                ['matricule' => $matricule], // condition
                [ // valeurs à mettre à jour si trouvé ou à créer
                    'nom_prenom' => trim($row[0]),
                    'diocese'    => trim($row[2]),
                    'email'      => trim($row[3]),
                    'telephone'  => trim($row[4]),
                ]
            );
        }
    }
}
