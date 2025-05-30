<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Mail\ConfirmationMail;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // Inscription
    public function register(Request $request)
    {
        $request->validate([
            'email'    => 'required|email|unique:users',
            'password' => 'required|min:6',
        ]);

        // Générer un code de confirmation à 5 chiffres
        $code = rand(10000, 99999);

        $user = User::create([
            'email'             => $request->email,
            'password'          => Hash::make($request->password),
            'confirmation_code' => $code,
            'is_verified'       => false,
        ]);

        // Envoyer le mail de confirmation
        Mail::to($user->email)->send(new ConfirmationMail($code));

        return response()->json(['message' => 'Inscription réussie. Vérifiez votre email pour le code de confirmation.'], 201);
    }

    // Confirmation du code
    public function confirm(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code'  => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || $user->confirmation_code != $request->code) {
            return response()->json(['message' => 'Code de confirmation invalide.'], 400);
        }

        $user->is_verified = true;
        $user->confirmation_code = null;
        $user->save();

        // Création d'un token via Sanctum
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Confirmation réussie',
            'token'   => $token,
            'user'    => $user
        ]);
    }

    // Connexion
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Les identifiants sont incorrects.'],
            ]);
        }

        if (!$user->is_verified) {
            return response()->json(['message' => 'Compte non vérifié.'], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Connexion réussie',
            'token'   => $token,
            'user'    => $user
        ]);
    }
}
