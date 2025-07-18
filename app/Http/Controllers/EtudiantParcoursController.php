<?php

namespace App\Http\Controllers;

use App\Models\EtudiantParcours;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class EtudiantParcoursController extends Controller
{
    public function index()
    {
        $parcours = EtudiantParcours::with(['etudiant', 'annee_academique', 'niveau'])->orderBy('id')->get();
        return response()->json($parcours);
    }
}

