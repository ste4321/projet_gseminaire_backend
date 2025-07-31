<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ContactController;
// use App\Http\Controllers\AuthController;
use App\Http\Controllers\OlonaController;
// use App\Http\Controllers\UtilisateurController;
use App\Models\Olona;
// use App\Models\Utilisateur;
use App\Http\Controllers\EmploiDuTempsController;
use App\Http\Controllers\EnseignantController;
use App\Http\Controllers\AnneeAcaController;
use App\Http\Controllers\EtudiantController;
use App\Http\Controllers\AnnonceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\InfoController;
use App\Http\Controllers\NiveauController;
use App\Http\Controllers\MatiereController;
use App\Http\Controllers\SemestreController;
use App\Http\Controllers\EtudiantParcoursController;
use App\Http\Controllers\EtudiantImportController;
use App\Http\Controllers\NoteController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
});

// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });

Route::post('/postContact', [ContactController::class, 'store']);
Route::get('/getContact', [ContactController::class, 'index']);

// Route::get('/getOlona', [OlonaController::class, 'index']);
Route::get('/emplois', [EmploiDuTempsController::class, 'index']);
Route::post('/emplois', [EmploiDuTempsController::class, 'store']);
Route::put('/emplois/{id}', [EmploiDuTempsController::class, 'update']);
Route::delete('/emplois/{id}', [EmploiDuTempsController::class, 'destroy']);
Route::post('/emplois/{id}/upload', [EmploiDuTempsController::class, 'updateImage']);
Route::post('/emplois', [EmploiDuTempsController::class, 'store']);


Route::get('/enseignants', [EnseignantController::class, 'index']);
Route::put('/enseignants/{id}', [EnseignantController::class, 'update']);
Route::delete('/enseignants/{id}', [EnseignantController::class, 'destroy']);
Route::post('/enseignants', [EnseignantController::class, 'store']);
Route::get('/enseignants/{id}/cession', [EnseignantController::class, 'showCession']);
Route::post('/enseignants/{id}/cession', [EnseignantController::class, 'assignCession']);


Route::get('/annee_aca', [AnneeAcaController::class, 'index']);
Route::apiResource('etudiants', EtudiantController::class);
// Route::get('etudiants/{id_annee}/{id_niveau}', [EtudiantController::class, 'parcoursParAnneeEtNiveau']);



Route::get('/annonces', [AnnonceController::class, 'index']);
Route::post('/annonces', [AnnonceController::class, 'store']);
Route::put('/annonces/{id}', [AnnonceController::class, 'update']);
Route::delete('/annonces/{id}', [AnnonceController::class, 'destroy']);

Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

Route::get('/infos', [InfoController::class, 'index']);
Route::post('/infos', [InfoController::class, 'store']);
Route::put('/infos/{id}', [InfoController::class, 'update']);
Route::delete('/infos/{id}', [InfoController::class, 'destroy']);

Route::apiResource('/niveaux', NiveauController::class);

// Route::apiResource('matieres', MatiereController::class);
// Routes pour les matières
Route::prefix('matieres')->group(function () {
    // Récupérer toutes les matières
    Route::get('/', [MatiereController::class, 'index']);
    
    // Récupérer les matières par niveau
    Route::get('/niveau/{niveau}', [MatiereController::class, 'getMatieresByNiveau']);
    
    // Récupérer les matières par semestre et niveau
    Route::get('/semestre/{semestreId}/niveau/{niveau}', [MatiereController::class, 'getMatieresBySemestreAndNiveau']);
    
    // Récupérer une matière spécifique
    Route::get('/{id}', [MatiereController::class, 'show']);
    
    // Créer une nouvelle matière
    Route::post('/', [MatiereController::class, 'store']);
    
    // Mettre à jour une matière
    Route::put('/{id}', [MatiereController::class, 'update']);
    
    // Supprimer une matière
    Route::delete('/{id}', [MatiereController::class, 'destroy']);
});


Route::apiResource('semestres', SemestreController::class);

Route::get('/etudiant_parcours', [EtudiantParcoursController::class, 'index']);

Route::post('/etudiants/import', [EtudiantImportController::class, 'import']);

// Routes pour les notes
Route::prefix('notes')->group(function () {
    // Récupérer les notes d'un étudiant par son parcours
    Route::get('/etudiant-parcours/{etudiantParcoursId}', [NoteController::class, 'getNotesByEtudiantParcours']);
    
    // Mettre à jour les notes en lot
    Route::post('/update-bulk', [NoteController::class, 'updateBulkNotes']);
    
    // Créer ou mettre à jour une note individuelle
    Route::post('/create-or-update', [NoteController::class, 'createOrUpdateNote']);
    
    // Supprimer une note
    Route::delete('/{id}', [NoteController::class, 'deleteNote']);
    
    // Récupérer toutes les notes d'un étudiant avec détails
    Route::get('/etudiant/{etudiantId}', [NoteController::class, 'getDetailedNotesByEtudiant']);
    Route::get('/etudiant/{etudiantId}/annee/{anneeAcademique}', [NoteController::class, 'getDetailedNotesByEtudiant']);
    
    // Calculer la moyenne d'un étudiant
    Route::get('/moyenne/{etudiantParcoursId}', [NoteController::class, 'calculateMoyenne']);
});