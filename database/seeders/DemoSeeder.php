<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\AnneeAca;
use App\Models\Annonce;
use App\Models\Enseignant;
use App\Models\Etudiant;
use App\Models\EtudiantParcours;
use App\Models\Matiere;
use App\Models\Niveau;
use App\Models\Note;
use App\Models\Semestre;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Données fictives pour tester l'application en local (jamais en production).
 *
 *   php artisan db:seed --class=DemoSeeder
 *
 * Comptes créés (mot de passe : Demo-GSTF-2026) :
 *   admin@demo.gstf.local, prof1@demo.gstf.local, etudiant1@demo.gstf.local
 */
class DemoSeeder extends Seeder
{
    public const MOT_DE_PASSE = 'Demo-GSTF-2026';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DemoSeeder refusé en production.');
            return;
        }

        $hash = Hash::make(self::MOT_DE_PASSE);
        $compte = fn (string $email, string $role) => User::firstOrCreate(
            ['email' => $email],
            ['password' => $hash, 'role' => $role, 'is_verified' => true, 'must_change_password' => false]
        );

        $admin = $compte('admin@demo.gstf.local', 'admin');
        Admin::firstOrCreate(['id_user_cession' => $admin->id], ['nom_prenom' => 'Père Recteur (démo)', 'email' => $admin->email]);

        $annee = AnneeAca::firstOrCreate(['annee_aca' => '2025-2026']);
        $niveaux = collect(['L1', 'L2', 'L3'])->mapWithKeys(fn ($n) => [$n => Niveau::firstOrCreate(['niveau' => $n])]);
        $s1 = Semestre::firstOrCreate(['semestre' => 'Semestre 1'], ['code_semestre' => 'S1']);
        $s2 = Semestre::firstOrCreate(['semestre' => 'Semestre 2'], ['code_semestre' => 'S2']);

        $matieres = collect();
        foreach ([
            ['Introduction à la théologie', 'THEO', 'L1', 4, $s1], ['Latin', 'LAT', 'L1', 2, $s1], ['Grec biblique', 'GREC', 'L1', 2, $s2],
            ['Écriture sainte', 'ECR', 'L2', 4, $s1], ['Patrologie', 'PATR', 'L2', 3, $s2],
            ['Théologie morale', 'MOR', 'L3', 4, $s1], ['Droit canonique', 'DCAN', 'L3', 3, $s2],
        ] as [$nom, $code, $niveau, $coef, $semestre]) {
            $matieres->push(Matiere::firstOrCreate(['code_matiere' => $code], [
                'matiere' => $nom, 'heures' => 30, 'id_niveau' => $niveaux[$niveau]->id,
                'coefficient' => (string) $coef, 'id_semestre' => $semestre->id,
            ]));
        }

        foreach (['Père Jean Rakoto', 'Père Paul Randria', 'Sœur Marie Rasoa'] as $i => $nom) {
            $user = $compte('prof' . ($i + 1) . '@demo.gstf.local', 'prof');
            Enseignant::firstOrCreate(['id_user_cession' => $user->id], ['nom_prenom' => $nom, 'email' => $user->email]);
        }

        $dioceses = ['Antsirabe', 'Antananarivo', 'Antsiranana', 'Miarinarivo', 'Mahajanga', 'Fianarantsoa', 'Trinitaires', 'Carmes', ''];
        $repartition = ['L1' => 18, 'L2' => 15, 'L3' => 12];
        $n = 0;
        foreach ($repartition as $niveau => $effectif) {
            for ($i = 0; $i < $effectif; $i++) {
                $n++;
                $matricule = sprintf('DEMO%03d', $n);
                $user = $n === 1 ? $compte('etudiant1@demo.gstf.local', 'etudiant') : null;
                $etudiant = Etudiant::firstOrCreate(['matricule' => $matricule], [
                    'nom_prenom'      => "Séminariste {$n}",
                    'diocese'         => $dioceses[$n % count($dioceses)] ?: null,
                    'email'           => $user?->email,
                    'id_user_cession' => $user?->id,
                ]);
                $parcours = EtudiantParcours::firstOrCreate(
                    ['id_etudiant' => $etudiant->id, 'id_annee_aca' => $annee->id],
                    ['id_niveau' => $niveaux[$niveau]->id, 'statut' => 'inscrit', 'date_inscription' => now(), 'matricule' => $matricule]
                );
                // Environ deux tiers des notes saisies
                foreach ($matieres->where('id_niveau', $niveaux[$niveau]->id) as $matiere) {
                    if (($n + $matiere->id) % 3 !== 0) {
                        Note::firstOrCreate(
                            ['id_etudiant_parcours' => $parcours->id, 'id_matiere' => $matiere->id],
                            ['note' => 8 + (($n * 7 + $matiere->id * 3) % 12), 'statut' => '']
                        );
                    }
                }
            }
        }

        // Quelques comptes en attente d'activation, pour voir les indicateurs
        foreach ([2, 3] as $i) {
            $user = User::firstOrCreate(['email' => "nouveau{$i}@demo.gstf.local"], [
                'password' => $hash, 'role' => 'etudiant', 'is_verified' => false, 'must_change_password' => true,
            ]);
            Etudiant::where('matricule', sprintf('DEMO%03d', $i))->update(['id_user_cession' => $user->id, 'email' => $user->email]);
        }

        foreach ([
            ['Rentrée académique', 'La messe de rentrée aura lieu lundi à 7 h à la chapelle.', 'L1'],
            ['Examens du premier semestre', 'Le calendrier des examens est affiché au secrétariat.', 'L2'],
            ['Retraite spirituelle', 'Retraite du 12 au 15 : présence obligatoire.', 'L3'],
        ] as [$titre, $texte, $niveau]) {
            Annonce::firstOrCreate(['titre' => $titre], [
                'description' => $texte, 'expediteur' => 'Administration',
                'id_annee_aca' => $annee->id, 'id_niveau' => $niveaux[$niveau]->id,
            ]);
        }

        $this->command?->info('Données de démonstration créées. Mot de passe : ' . self::MOT_DE_PASSE);
    }
}
