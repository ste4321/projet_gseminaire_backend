<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalendrierController;
use App\Http\Controllers\ConfirmationController;
use App\Http\Controllers\ConsultationController;
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
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\ProgrammeController;
use App\Http\Controllers\PublicationNoteController;
use App\Http\Controllers\ReinscriptionController;
use App\Http\Controllers\SortieEtudiantController;
use App\Http\Controllers\EspaceEtudiantController;
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
Route::get('/calendrier', [CalendrierController::class, 'public']);

// ─── 2. Utilisateur connecté ──────────────────────────────────────────────
Route::middleware(['auth:sanctum', 'consultation.lecture'])->group(function () {
    // Session (accessible même si le compte vient d'être désactivé, pour pouvoir se déconnecter)
    Route::post('/logout', [AuthController::class, 'logout']);
    // Fin de la consultation de l'espace d'une autre personne (voir ConsultationController)
    Route::post('/consultation/fin', [ConsultationController::class, 'fin']);
    Route::get('/me', [AuthController::class, 'me']);
    // Signal de présence de l'application ouverte (statut « En ligne » des comptes)
    Route::post('/presence', [AuthController::class, 'presence'])->middleware('throttle:10,1');
    Route::post('/refresh', [AuthController::class, 'refresh']);
    // Accessible avec un mot de passe provisoire (c'est justement pour le remplacer)
    Route::post('/password/change', [PasswordController::class, 'change'])->middleware('throttle:10,1');

    // Lecture commune aux trois rôles
    Route::middleware('role:admin,prof,etudiant')->group(function () {
        Route::get('/annonces', [AnnonceController::class, 'index']);
        Route::get('/emplois', [EmploiDuTempsController::class, 'index']);
        Route::get('/enseignants', [EnseignantController::class, 'index']);
        Route::get('/annee_aca', [AnneeAcaController::class, 'index']);
        Route::get('/niveaux', [NiveauController::class, 'index']);
        Route::get('/semestres', [SemestreController::class, 'index']);
    });

    // Espace étudiant : uniquement ses propres données
    Route::middleware('role:etudiant')->prefix('etudiant')->group(function () {
        Route::get('/parcours', [EspaceEtudiantController::class, 'parcours']);
        Route::get('/releve', [EspaceEtudiantController::class, 'releve']);
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
        // Départ d'un étudiant (diplômé, abandon, transfert, exclusion) et annulation
        Route::post('/etudiants/{id}/sortie', [SortieEtudiantController::class, 'store'])->whereNumber('id');
        Route::delete('/etudiants/{id}/sortie', [SortieEtudiantController::class, 'destroy'])->whereNumber('id');
        // Assistant de réinscription : passage à l'année suivante
        Route::get('/reinscriptions', [ReinscriptionController::class, 'index']);
        Route::post('/reinscriptions', [ReinscriptionController::class, 'store']);
        Route::get('/etudiant_parcours', [EtudiantParcoursController::class, 'index']);

        // Annonces
        Route::post('/annonces', [AnnonceController::class, 'store']);
        Route::put('/annonces/{id}', [AnnonceController::class, 'update']);
        Route::delete('/annonces/{id}', [AnnonceController::class, 'destroy']);

        // Infos (site vitrine)
        // Code à recopier avant une publication (notes, actualité)
        Route::post('/confirmations', [ConfirmationController::class, 'store'])->middleware('throttle:20,1');
        Route::post('/infos', [InfoController::class, 'store']);
        Route::put('/infos/{id}', [InfoController::class, 'update']);
        Route::delete('/infos/{id}', [InfoController::class, 'destroy']);

        // Calendrier académique (site vitrine)
        Route::get('/calendriers', [CalendrierController::class, 'index']);
        Route::post('/calendriers', [CalendrierController::class, 'store']);
        Route::put('/calendriers/{id}', [CalendrierController::class, 'update'])->whereNumber('id');
        Route::delete('/calendriers/{id}', [CalendrierController::class, 'destroy'])->whereNumber('id');
        Route::post('/calendriers/{id}/evenements', [CalendrierController::class, 'storeEvenement'])->whereNumber('id');
        Route::put('/calendrier-evenements/{id}', [CalendrierController::class, 'updateEvenement'])->whereNumber('id');
        Route::delete('/calendrier-evenements/{id}', [CalendrierController::class, 'destroyEvenement'])->whereNumber('id');

        // Référentiels
        Route::post('/annee_aca', [AnneeAcaController::class, 'store']);
        Route::put('/annee_aca/{id}', [AnneeAcaController::class, 'update'])->whereNumber('id');
        Route::delete('/annee_aca/{id}', [AnneeAcaController::class, 'destroy'])->whereNumber('id');
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

        // Programme d'une année académique (matières enseignées, coefficient de l'année)
        Route::prefix('programme')->group(function () {
            Route::post('/', [ProgrammeController::class, 'store']);
            Route::delete('/', [ProgrammeController::class, 'destroy']);
            Route::post('/copier', [ProgrammeController::class, 'copier']);
        });

        // Notes (import déclaré avant les routes paramétrées)
        Route::prefix('notes')->group(function () {
            Route::post('/import', [NoteImportController::class, 'import']);
            Route::get('/publications', [PublicationNoteController::class, 'index']);
            Route::post('/publications', [PublicationNoteController::class, 'store']);
            Route::delete('/publications', [PublicationNoteController::class, 'destroy']);
            Route::get('/etudiant-parcours/{etudiantParcoursId}', [NoteController::class, 'getNotesByEtudiantParcours']);
            Route::post('/update-bulk', [NoteController::class, 'updateBulkNotes']);
            Route::post('/create-or-update', [NoteController::class, 'createOrUpdateNote']);
            Route::delete('/{id}', [NoteController::class, 'deleteNote'])->whereNumber('id');
            Route::get('/etudiant/{etudiantId}', [NoteController::class, 'getDetailedNotesByEtudiant']);
            Route::get('/etudiant/{etudiantId}/annee/{anneeAcademique}', [NoteController::class, 'getDetailedNotesByEtudiant']);
            Route::get('/moyenne/{etudiantParcoursId}', [NoteController::class, 'calculateMoyenne']);
            Route::get('/releve/{etudiantParcoursId}', [NoteController::class, 'releve']);
        });

        // Comptes utilisateurs (routes fixes avant {id})
        Route::get('/user-cessions', [UserCessionController::class, 'index']);
        Route::get('/user-cessions/stats', [UserCessionController::class, 'stats']);
        Route::get('/user-cessions/presence', [UserCessionController::class, 'presence']);
        Route::patch('/user-cessions/bulk-toggle', [UserCessionController::class, 'bulkToggle']);
        Route::post('/user-cessions/bulk-delete', [UserCessionController::class, 'bulkDestroy']);
        Route::post('/user-cessions/admins', [UserCessionController::class, 'storeAdmin'])->middleware('throttle:10,1');
        Route::patch('/user-cessions/{id}/toggle', [UserCessionController::class, 'toggle']);
        Route::post('/user-cessions/{id}/reset-password', [PasswordController::class, 'reset']);
        Route::delete('/user-cessions/{id}', [UserCessionController::class, 'destroy'])->whereNumber('id');

        // Ouvrir l'espace d'un étudiant ou d'un enseignant, en lecture seule
        Route::post('/consultation/{userId}', [ConsultationController::class, 'demarrer'])
            ->whereNumber('userId')->middleware('throttle:20,1');
    });
});
