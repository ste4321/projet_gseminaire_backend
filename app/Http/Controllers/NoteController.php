<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Note;
use App\Models\Matiere;
use App\Models\EtudiantParcours;
use App\Services\ReleveService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class NoteController extends Controller
{
    /**
     * Récupérer les notes d'un étudiant par son parcours
     */
    public function getNotesByEtudiantParcours($etudiantParcoursId)
    {
        try {
            $notes = Note::where('id_etudiant_parcours', $etudiantParcoursId)
                        ->with('matiere')
                        ->get();
            
            return response()->json($notes, 200);
        } catch (\Exception $e) {
            return $this->serverError('Erreur lors de la récupération des notes', $e);
        }
    }


    /**
     * Mettre à jour les notes en lot
     */
    public function updateBulkNotes(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'etudiant_parcours_id' => 'required|exists:etudiant_parcours,id',
            'notes' => 'required|array',
            'notes.*.id_matiere' => 'required|exists:matieres,id',
            'notes.*.note' => 'nullable|numeric|min:0|max:20',
            'notes.*.statut' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Données invalides',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $etudiantParcoursId = $request->etudiant_parcours_id;
            $notes = $request->notes;

            foreach ($notes as $noteData) {
                // 0 est une note valide : seule une valeur vide supprime la note
                $valeur = $noteData['note'] ?? null;
                if ($valeur !== null && $valeur !== '') {
                    Note::updateOrCreate(
                        [
                            'id_etudiant_parcours' => $etudiantParcoursId,
                            'id_matiere' => $noteData['id_matiere']
                        ],
                        [
                            'note' => $valeur,
                            'statut' => $noteData['statut'] ?? '',
                            'updated_at' => now()
                        ]
                    );
                } else {
                    // Si la note est vide, supprimer l'enregistrement s'il existe
                    Note::where('id_etudiant_parcours', $etudiantParcoursId)
                        ->where('id_matiere', $noteData['id_matiere'])
                        ->delete();
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Notes mises à jour avec succès',
                'success' => true
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->serverError('Erreur lors de la mise à jour des notes', $e);
        }
    }

    /**
     * Créer ou mettre à jour une note individuelle
     */
    public function createOrUpdateNote(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_etudiant_parcours' => 'required|exists:etudiant_parcours,id',
            'id_matiere' => 'required|exists:matieres,id',
            'note' => 'nullable|numeric|min:0|max:20',
            'statut' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Données invalides',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $note = Note::updateOrCreate(
                [
                    'id_etudiant_parcours' => $request->id_etudiant_parcours,
                    'id_matiere' => $request->id_matiere
                ],
                [
                    'note' => $request->note,
                    'statut' => $request->statut ?? '',
                    'updated_at' => now()
                ]
            );

            return response()->json([
                'message' => 'Note sauvegardée avec succès',
                'note' => $note,
                'success' => true
            ], 200);

        } catch (\Exception $e) {
            return $this->serverError('Erreur lors de la sauvegarde de la note', $e);
        }
    }

    /**
     * Supprimer une note
     */
    public function deleteNote($id)
    {
        try {
            $note = Note::findOrFail($id);
            $note->delete();

            return response()->json([
                'message' => 'Note supprimée avec succès',
                'success' => true
            ], 200);

        } catch (\Exception $e) {
            return $this->serverError('Erreur lors de la suppression de la note', $e);
        }
    }

    /**
     * Récupérer toutes les notes d'un étudiant avec détails
     */
    public function getDetailedNotesByEtudiant($etudiantId, $anneeAcademique = null)
    {
        try {
            $query = Note::whereHas('etudiantParcours', function($q) use ($etudiantId, $anneeAcademique) {
                $q->where('id_etudiant', $etudiantId);
                if ($anneeAcademique) {
                    $q->where('id_annee_aca', $anneeAcademique);
                }
            })
            ->with(['matiere', 'etudiantParcours.etudiant', 'etudiantParcours.niveau', 'etudiantParcours.annee_academique']);

            $notes = $query->get();

            return response()->json($notes, 200);

        } catch (\Exception $e) {
            return $this->serverError('Erreur lors de la récupération des notes détaillées', $e);
        }
    }

    /**
     * Relevé de notes complet d'une inscription (bulletin) : mêmes calculs que l'espace étudiant
     */
    public function releve($etudiantParcoursId, ReleveService $releves)
    {
        return response()->json($releves->pour(EtudiantParcours::findOrFail($etudiantParcoursId)));
    }

    /**
     * Calculer la moyenne d'un étudiant
     */
    public function calculateMoyenne($etudiantParcoursId)
    {
        try {
            $notes = Note::where('id_etudiant_parcours', $etudiantParcoursId)
                        ->with('matiere')
                        ->whereNotNull('note')
                        ->get();

            if ($notes->isEmpty()) {
                return response()->json([
                    'moyenne' => null,
                    'message' => 'Aucune note disponible'
                ], 200);
            }

            $totalNotes = 0;
            $totalCoefficients = 0;

            foreach ($notes as $note) {
                $coefficient = $note->matiere->coefficient ?? 1;
                $totalNotes += $note->note * $coefficient;
                $totalCoefficients += $coefficient;
            }

            $moyenne = $totalCoefficients > 0 ? $totalNotes / $totalCoefficients : 0;

            return response()->json([
                'moyenne' => round($moyenne, 2),
                'total_notes' => $notes->count(),
                'success' => true
            ], 200);

        } catch (\Exception $e) {
            return $this->serverError('Erreur lors du calcul de la moyenne', $e);
        }
    }
}