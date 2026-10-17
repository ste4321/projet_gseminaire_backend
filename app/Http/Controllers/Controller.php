<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Log;
use Throwable;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Réponse 500 homogène : le détail technique va dans les logs,
     * jamais au client (sauf en mode debug local).
     */
    protected function serverError(string $message, Throwable $e)
    {
        Log::error($message, [
            'exception' => get_class($e),
            'error'     => $e->getMessage(),
            'file'      => $e->getFile() . ':' . $e->getLine(),
        ]);

        $payload = ['message' => $message];
        if (config('app.debug')) {
            $payload['debug'] = $e->getMessage();
        }

        return response()->json($payload, 500);
    }
}
