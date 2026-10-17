<?php

namespace App\Http\Controllers;

use App\Services\ConfirmationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConfirmationController extends Controller
{
    /** POST /api/confirmations {action} : nouveau code à recopier avant une action sensible */
    public function store(Request $request, ConfirmationService $confirmations)
    {
        $request->validate(['action' => ['required', Rule::in(ConfirmationService::ACTIONS)]]);

        return response()->json([
            'code'       => $confirmations->emettre($request->user(), $request->action),
            'expire_dans' => ConfirmationService::CODE_TTL,
        ], 201);
    }
}
