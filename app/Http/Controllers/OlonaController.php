<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Olona; 

class OlonaController extends Controller
{
    public function index()
    {
        return response()->json(Olona::all());
    }
}
