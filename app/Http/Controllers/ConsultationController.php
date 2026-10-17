<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ConsultationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ConsultationController extends Controller
{
    /** POST /api/consultation/{userId} (admin) : ouvre l'espace d'un étudiant ou d'un enseignant, en lecture seule */
    public function demarrer(Request $request, int $userId, ConsultationService $consultations)
    {
        $admin = $request->user();
        $cible = User::findOrFail($userId);

        if (!in_array($cible->role, ConsultationService::ROLES_CONSULTABLES, true)) {
            return response()->json(['message' => 'Seuls les espaces étudiant et enseignant peuvent être consultés.'], 422);
        }

        [$jeton, $expire] = $consultations->demarrer($admin, $cible);

        Log::notice('Consultation ouverte', [
            'admin_id' => $admin->id, 'admin_email' => $admin->email,
            'cible_id' => $cible->id, 'cible_role' => $cible->role, 'ip' => $request->ip(),
        ]);

        return response()->json([
            'access_token' => $jeton,
            'token_type'   => 'Bearer',
            'expires_at'   => $expire->toISOString(),
            'user'         => $cible->payloadSession(),
            'consultation' => ['duree_minutes' => ConsultationService::DUREE_MINUTES],
        ]);
    }

    /** POST /api/consultation/fin : supprime le jeton de consultation en cours */
    public function fin(Request $request)
    {
        if (!ConsultationService::estEnConsultation($request)) {
            return response()->json(['message' => 'Aucune consultation en cours.'], 422);
        }

        Log::notice('Consultation fermée', [
            'admin_id' => ConsultationService::adminOrigine($request),
            'cible_id' => $request->user()->id,
        ]);
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Consultation terminée.']);
    }
}
