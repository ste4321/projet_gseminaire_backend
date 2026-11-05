<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // Envoi plus gros que ce que PHP accepte (post_max_size) : PHP a vidé la requête, Laravel la refuse
        // sans message. On explique pourquoi, avec la limite du serveur, au lieu d'une erreur muette.
        $this->renderable(function (PostTooLargeException $e, $request) {
            if (!$this->shouldReturnJson($request, $e)) {
                return null;
            }
            // Limite annoncée : celle de l'application (10 Mo pour une leçon), ou celle de PHP si elle est plus basse
            $limite = min(10, (int) floor(UploadedFile::getMaxFilesize() / 1024 / 1024)) ?: 1;

            return response()->json([
                'message' => "Le fichier est trop volumineux : {$limite} Mo maximum. "
                    . 'Réduisez sa taille (PDF compressé, images allégées) ou découpez-le en plusieurs leçons.',
                'errors'  => ['fichier' => ["Fichier trop volumineux : {$limite} Mo maximum."]],
            ], 413);
        });
    }

    /**
     * Toutes les routes /api répondent en JSON (401, 403, 404, 422, 500...),
     * même si le client n'envoie pas l'en-tête Accept: application/json.
     * Avec APP_DEBUG=false, Laravel masque le détail des erreurs 500.
     */
    protected function shouldReturnJson($request, Throwable $e): bool
    {
        return $request->is('api/*') || parent::shouldReturnJson($request, $e);
    }
}
