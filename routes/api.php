<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\EmploiDuTempsController;
use App\Http\Controllers\EnseignantController;
use App\Http\Controllers\AnneeAcaController;
use App\Http\Controllers\EtudiantController;
use App\Http\Controllers\AnnonceController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\InfoController;
use App\Http\Controllers\NiveauController;
use App\Http\Controllers\MatiereController;
use App\Http\Controllers\SemestreController;
use App\Http\Controllers\EtudiantParcoursController;
use App\Http\Controllers\EtudiantImportController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\NoteImportController;
use App\Http\Controllers\UserCessionController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| 1. Routes publiques  : connexion + site vitrine (projet_gseminaire_frontend)
| 2. Routes connectées : tout utilisateur authentifié et activé
| 3. Routes admin      : gestion complète
|
*/

// ─── 1. Public ────────────────────────────────────────────────────────────
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

// Site vitrine
Route::post('/postContact', [ContactController::class, 'store'])->middleware('throttle:5,1');
Route::get('/infos', [InfoController::class, 'index']);

// ─── 2. Utilisateur connecté ──────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    // Session (accessible même si le compte vient d'être désactivé, pour pouvoir se déconnecter)
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/refresh', [AuthController::class, 'refresh']);

    // Lecture commune aux trois rôles
    Route::middleware('role:admin,prof,etudiant')->group(function () {
        Route::get('/annonces', [AnnonceController::class, 'index']);
        Route::get('/emplois', [EmploiDuTempsController::class, 'index']);
        Route::get('/enseignants', [EnseignantController::class, 'index']);
        Route::get('/annee_aca', [AnneeAcaController::class, 'index']);
        Route::get('/niveaux', [NiveauController::class, 'index']);
        Route::get('/semestres', [SemestreController::class, 'index']);
    });

    // ─── 3. Administration ────────────────────────────────────────────────
    Route::middleware('role:admin')->group(function () {
        Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
        Route::get('/getContact', [ContactController::class, 'index']);

        // Emploi du temps
        Route::post('/emplois', [EmploiDuTempsController::class, 'store']);
        Route::delete('/emplois/{id}', [EmploiDuTempsController::class, 'destroy']);
        Route::post('/emplois/{id}/upload', [EmploiDuTempsController::class, 'updateImage']);

        // Enseignants
        Route::post('/enseignants', [EnseignantController::class, 'store']);
        Route::put('/enseignants/{id}', [EnseignantController::class, 'update']);
        Route::delete('/enseignants/{id}', [EnseignantController::class, 'destroy']);
        Route::get('/enseignants/{id}/cession', [EnseignantController::class, 'showCession']);
        Route::post('/enseignants/{id}/cession', [EnseignantController::class, 'assignCession']);

        // Étudiants (import déclaré avant la ressource)
        Route::post('/etudiants/import', [EtudiantImportController::class, 'import']);
        Route::apiResource('etudiants', EtudiantController::class);
        Route::get('/etudiant_parcours', [EtudiantParcoursController::class, 'index']);

        // Annonces
        Route::post('/annonces', [AnnonceController::class, 'store']);
        Route::put('/annonces/{id}', [AnnonceController::class, 'update']);
        Route::delete('/annonces/{id}', [AnnonceController::class, 'destroy']);

        // Infos (site vitrine)
        Route::post('/infos', [InfoController::class, 'store']);
        Route::put('/infos/{id}', [InfoController::class, 'update']);
        Route::delete('/infos/{id}', [InfoController::class, 'destroy']);

        // Référentiels
        Route::apiResource('niveaux', NiveauController::class)->except('index');
        Route::apiResource('semestres', SemestreController::class)->except('index');

        Route::prefix('matieres')->group(function () {
            Route::get('/', [MatiereController::class, 'index']);
            Route::get('/niveau/{niveau}', [MatiereController::class, 'getMatieresByNiveau']);
            Route::get('/{id}', [MatiereController::class, 'show']);
            Route::post('/', [MatiereController::class, 'store']);
            Route::put('/{id}', [MatiereController::class, 'update']);
            Route::delete('/{id}', [MatiereController::class, 'destroy']);
        });

        // Notes (import déclaré avant les routes paramétrées)
        Route::prefix('notes')->group(function () {
            Route::post('/import', [NoteImportController::class, 'import']);
            Route::get('/etudiant-parcours/{etudiantParcoursId}', [NoteController::class, 'getNotesByEtudiantParcours']);
            Route::post('/update-bulk', [NoteController::class, 'updateBulkNotes']);
            Route::post('/create-or-update', [NoteController::class, 'createOrUpdateNote']);
            Route::delete('/{id}', [NoteController::class, 'deleteNote']);
            Route::get('/etudiant/{etudiantId}', [NoteController::class, 'getDetailedNotesByEtudiant']);
            Route::get('/etudiant/{etudiantId}/annee/{anneeAcademique}', [NoteController::class, 'getDetailedNotesByEtudiant']);
            Route::get('/moyenne/{etudiantParcoursId}', [NoteController::class, 'calculateMoyenne']);
        });

        // Comptes utilisateurs (routes fixes avant {id})
        Route::get('/user-cessions', [UserCessionController::class, 'index']);
        Route::get('/user-cessions/stats', [UserCessionController::class, 'stats']);
        Route::patch('/user-cessions/bulk-toggle', [UserCessionController::class, 'bulkToggle']);
        Route::patch('/user-cessions/{id}/toggle', [UserCessionController::class, 'toggle']);
    });
});
