<?php

namespace App\Http\Middleware;

use App\Services\ConsultationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pendant qu'un administrateur consulte l'espace d'une autre personne, rien ne peut être modifié :
 * seules la lecture et la sortie de consultation sont autorisées.
 */
class LectureSeuleConsultation
{
    private const ECRITURES_AUTORISEES = ['api/consultation/fin', 'api/logout'];

    public function handle(Request $request, Closure $next): Response
    {
        if (
            ConsultationService::estEnConsultation($request)
            && !$request->isMethodSafe()
            && !$request->is(...self::ECRITURES_AUTORISEES)
        ) {
            return response()->json([
                'message'    => 'Mode consultation : aucune modification n\'est possible depuis l\'espace d\'une autre personne.',
                'error_type' => 'consultation_lecture_seule',
            ], 403);
        }

        return $next($request);
    }
}
