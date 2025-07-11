<?php

namespace App\Http\Controllers;

use App\Models\AnneeAca;

class AnneeAcaController extends Controller
{
    public function index()
    {
        return AnneeAca::orderByDesc('id')->get();
    }
}
