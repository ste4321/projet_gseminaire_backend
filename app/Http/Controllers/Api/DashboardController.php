<?php
// app/Http/Controllers/Api/DashboardController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Enseignant;
use App\Models\Etudiant;

class DashboardController extends Controller
{
    public function stats()
    {
        return response()->json([
            'total_etudiants' => Etudiant::count(),
            'total_profs' => Enseignant::count(),
        ]);
    }
}
