<?php

namespace App\Services;

use App\Models\AnneeAca;
use App\Models\EtudiantParcours;
use App\Models\Niveau;
use Illuminate\Support\Facades\DB;

/**
 * Passage d'une année académique à la suivante.
 *
 * 1. propositions() : pour chaque étudiant de l'année source (non sorti), une décision tirée de son résultat :
 *    - Validé     → passage au niveau suivant (fin de cycle : diplômé) ;
 *    - Rattrapage → redoublement ;
 *    - notes incomplètes → redoublement, signalé « à vérifier ».
 * 2. appliquer() : l'administration confirme (ou corrige) chaque décision ; tout est enregistré d'un coup.
 *
 * Le compte de connexion ne change jamais : même email, même mot de passe d'une année à l'autre.
 */
class ReinscriptionService
{
    public const PASSAGE = 'passage';
    public const REDOUBLEMENT = 'redoublement';
    public const DIPLOME = 'diplome';
    public const IGNORER = 'ignorer';
    public const DECISIONS = [self::PASSAGE, self::REDOUBLEMENT, self::DIPLOME, self::IGNORER, ...EtudiantParcours::SORTIES];

    public function __construct(private ReleveService $releves, private SortieService $sorties)
    {
    }

    public function propositions(AnneeAca $source, AnneeAca $cible): array
    {
        $niveaux = Niveau::all()->keyBy('id');
        $suivants = $niveaux->map(fn (Niveau $n) => $n->suivant()?->id);
        $dejaInscrits = EtudiantParcours::where('id_annee_aca', $cible->id)->pluck('id_niveau', 'id_etudiant');

        return EtudiantParcours::with(['etudiant', 'niveau', 'annee_academique'])
            ->where('id_annee_aca', $source->id)
            ->whereHas('etudiant', fn ($q) => $q->whereNull('date_sortie'))
            ->get()
            ->map(function (EtudiantParcours $p) use ($niveaux, $suivants, $dejaInscrits) {
                $general = $this->releves->pour($p)['general'];
                $suivant = $suivants[$p->id_niveau] ?? null;

                [$decision, $niveauCible, $aVerifier] = match ($general['statut']) {
                    ReleveService::VALIDE     => $suivant ? [self::PASSAGE, $suivant, false] : [self::DIPLOME, null, false],
                    ReleveService::RATTRAPAGE => [self::REDOUBLEMENT, $p->id_niveau, false],
                    default                   => [self::REDOUBLEMENT, $p->id_niveau, true], // notes incomplètes
                };

                return [
                    'parcours_id'   => $p->id,
                    'etudiant'      => [
                        'id'         => $p->etudiant->id,
                        'matricule'  => $p->etudiant->matricule,
                        'nom_prenom' => $p->etudiant->nom_prenom,
                    ],
                    'niveau'        => ['id' => $p->id_niveau, 'niveau' => $niveaux[$p->id_niveau]?->niveau],
                    'moyenne'       => $general['moyenne'],
                    'resultat'      => $general['statut'],
                    'notes'         => ['saisies' => $general['notes_saisies'], 'total' => $general['matieres_total']],
                    'decision'      => $decision,
                    'niveau_cible'  => $niveauCible,
                    'niveau_suivant' => $suivant,
                    'a_verifier'    => $aVerifier,
                    // Déjà inscrit l'année cible (import Excel, réinscription précédente) : rien ne sera fait
                    'deja_inscrit'  => $dejaInscrits->has($p->id_etudiant)
                        ? ['niveau' => $niveaux[$dejaInscrits[$p->id_etudiant]]?->niveau]
                        : null,
                ];
            })
            ->sortBy([['niveau.niveau', 'asc'], ['etudiant.nom_prenom', 'asc']])
            ->values()
            ->all();
    }

    /**
     * @param array<int, array{parcours_id: int, decision: string, niveau_cible?: ?int, motif?: ?string}> $decisions
     * @return array<string, int> nombre d'étudiants par décision appliquée (+ deja_inscrits)
     */
    public function appliquer(AnneeAca $source, AnneeAca $cible, array $decisions): array
    {
        $bilan = array_fill_keys([...self::DECISIONS, 'deja_inscrits'], 0);

        DB::transaction(function () use ($source, $cible, $decisions, &$bilan) {
            $parcoursSource = EtudiantParcours::with(['etudiant', 'niveau'])
                ->where('id_annee_aca', $source->id)
                ->whereIn('id', array_column($decisions, 'parcours_id'))
                ->get()
                ->keyBy('id');
            $dejaInscrits = EtudiantParcours::where('id_annee_aca', $cible->id)->pluck('id_etudiant')->flip();

            foreach ($decisions as $d) {
                $p = $parcoursSource[$d['parcours_id']] ?? null;
                if (!$p || $d['decision'] === self::IGNORER) {
                    $bilan[self::IGNORER]++;
                    continue;
                }

                if (in_array($d['decision'], [self::PASSAGE, self::REDOUBLEMENT], true)) {
                    if ($dejaInscrits->has($p->id_etudiant)) {
                        $bilan['deja_inscrits']++;
                        continue;
                    }
                    // Niveau imposé par la décision (le niveau éventuellement envoyé est ignoré) :
                    // passage = niveau suivant, redoublement = même niveau
                    $niveau = $d['decision'] === self::PASSAGE
                        ? Niveau::find($p->id_niveau)?->suivant()?->id
                        : $p->id_niveau;
                    if (!$niveau) {
                        $bilan[self::IGNORER]++; // pas de niveau suivant : à traiter comme diplômé
                        continue;
                    }

                    EtudiantParcours::create([
                        'id_etudiant'      => $p->id_etudiant,
                        'id_annee_aca'     => $cible->id,
                        'id_niveau'        => $niveau,
                        'statut'           => EtudiantParcours::INSCRIT,
                        'date_inscription' => now(),
                        'matricule'        => $p->matricule ?? $p->etudiant->matricule,
                    ]);
                    $p->update(['statut' => $d['decision'] === self::PASSAGE ? EtudiantParcours::ADMIS : EtudiantParcours::REDOUBLANT]);
                    $bilan[$d['decision']]++;
                    continue;
                }

                // Diplômé, abandon, transfert, exclusion : fin du parcours
                $motif = $d['motif'] ?? ($d['decision'] === self::DIPLOME
                    ? 'Diplômé en ' . ($p->niveau?->niveau ?? '') . ' (' . $source->annee_aca . ')'
                    : null);
                $this->sorties->declarer($p->etudiant, $d['decision'] === self::DIPLOME ? EtudiantParcours::DIPLOME : $d['decision'], null, $motif, $p);
                $bilan[$d['decision']]++;
            }
        });

        return $bilan;
    }
}
