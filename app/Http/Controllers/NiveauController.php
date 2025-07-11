<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Niveau;

class NiveauController extends Controller
{
    public function index()
    {
        return Niveau::all();
    }

    public function store(Request $request)
    {
        $request->validate(['niveau' => 'required|string']);
        return Niveau::create($request->all());
    }

    public function show($id)
    {
        return Niveau::findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $niveau = Niveau::findOrFail($id);
        $niveau->update($request->all());
        return $niveau;
    }

    public function destroy($id)
    {
        Niveau::findOrFail($id)->delete();
        return response()->json(['message' => 'Supprimé']);
    }
}
