<?php

namespace App\Http\Controllers;

use App\Models\Annonce;
use App\Models\Enseignant;
use App\Models\Etudiant;
use App\Models\EtudiantParcours;
use App\Models\User;

/**
 * Profils consultés par l'administration (sans se connecter au compte de la personne).
 * Les notes de l'étudiant se chargent ensuite par inscription : GET /api/notes/releve/{parcours}.
 */
class ProfilController extends Controller
{
    /** GET /api/etudiants/{id}/profil */
    public function etudiant($id)
    {
        $etudiant = Etudiant::with('userCession')->findOrFail($id);

        $parcours = EtudiantParcours::with(['niveau:id,niveau', 'annee_academique:id,annee_aca'])
            ->where('id_etudiant', $etudiant->id)
            ->orderByDesc('id_annee_aca')
            ->get()
            ->map(fn ($p) => [
                'id'               => $p->id,
                'annee'            => $p->annee_academique?->annee_aca,
                'niveau'           => $p->niveau?->niveau,
                'statut'           => $p->statut,
                'date_inscription' => $p->date_inscription,
            ]);

        return [
            'etudiant' => $etudiant->only(['id', 'nom_prenom', 'matricule', 'diocese', 'email', 'telephone', 'created_at']),
            'compte'   => $this->compte($etudiant->userCession),
            'parcours' => $parcours,
        ];
    }

    /** GET /api/enseignants/{id}/profil */
    public function enseignant($id)
    {
        $enseignant = Enseignant::with('userCession')->findOrFail($id);

        // Les annonces d'un enseignant portent son nom comme expéditeur (renseigné par le serveur)
        $annonces = Annonce::with(['niveau:id,niveau', 'anneeAca:id,annee_aca'])
            ->where('expediteur', $enseignant->nom_prenom)
            ->latest()
            ->limit(5)
            ->get(['id', 'titre', 'id_niveau', 'id_annee_aca', 'created_at']);

        return [
            'enseignant' => $enseignant->only(['id', 'nom_prenom', 'email', 'telephone', 'adresse', 'created_at']),
            'compte'     => $this->compte($enseignant->userCession),
            'annonces'   => $annonces->map(fn ($a) => [
                'id'     => $a->id,
                'titre'  => $a->titre,
                'niveau' => $a->niveau?->niveau,
                'annee'  => $a->anneeAca?->annee_aca,
                'date'   => $a->created_at,
            ]),
        ];
    }

    /** État du compte de connexion, sans aucune donnée secrète */
    private function compte(?User $user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'id'                   => $user->id,
            'email'                => $user->email,
            'is_verified'          => (bool) $user->is_verified,
            'must_change_password' => (bool) $user->must_change_password,
            'password_changed_at'  => $user->password_changed_at,
            'created_at'           => $user->created_at,
        ];
    }
}
