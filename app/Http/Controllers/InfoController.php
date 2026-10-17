<?php

namespace App\Http\Controllers;

use App\Models\Info;
use Illuminate\Http\Request;

class InfoController extends Controller
{
    public function index()
    {
        return Info::latest()->get();
    }

    public function store(Request $request)
    {
        return response()->json(Info::create($this->validated($request)), 201);
    }

    public function update(Request $request, $id)
    {
        $info = Info::findOrFail($id);
        $info->update($this->validated($request));
        return $info;
    }

    public function destroy($id)
    {
        Info::findOrFail($id)->delete();
        return response()->json(['message' => 'Supprimé avec succès']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'auteur'      => ['required', 'string', 'max:255'],
            'titre'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
        ]);
    }
}
