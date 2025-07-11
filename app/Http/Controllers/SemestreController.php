<?php

namespace App\Http\Controllers;

use App\Models\Semestre;
use Illuminate\Http\Request;

class SemestreController extends Controller
{
    public function index()
    {
        return Semestre::all();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'semestre' => 'required|string',
            'code_semestre' => 'nullable|string',
        ]);

        return Semestre::create($validated);
    }

    public function show($id)
    {
        return Semestre::findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $semestre = Semestre::findOrFail($id);
        $semestre->update($request->only(['semestre', 'code_semestre']));
        return $semestre;
    }

    public function destroy($id)
    {
        Semestre::findOrFail($id)->delete();
        return response()->json(['message' => 'Supprimé avec succès']);
    }
}
