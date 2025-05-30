<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UtilisateurController;
use App\Models\Utilisateur;

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

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/postContact', [ContactController::class, 'store']);
Route::get('/getContact', [ContactController::class, 'index']);

// Route::post('/register', [AuthController::class, 'register']);
// Route::post('/confirm', [AuthController::class, 'confirm']);
// Route::post('/login', [AuthController::class, 'login']);



Route::get('/getUtilisateur', [UtilisateurController::class, 'index']);
Route::post('/login', function (Request $request) {
    $email = $request->input('email');
    $password = $request->input('password');

    $user = Utilisateur::where('email', $email)->first();

    if (!$user || $user->password !== $password) {
        return response()->json(['message' => 'Identifiants incorrects'], 401);
    }

    return response()->json([
        'message' => 'Connexion réussie',
        'role' => $user->role,
        'user' => [
            'id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
        ],
    ]);
});
