<?php

namespace App\Http\Controllers;

use App\Models\Edt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EmploiDuTempsController extends Controller
{
    public function index()
    {
        return Edt::orderBy('id', 'asc')->get();
    }

    public function updateImage(Request $request, $id)
    {
        $request->validate([
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            // 'email' => 'required|string|email',
        ]);

        $emploi = Edt::findOrFail($id);

        if ($request->hasFile('image')) {
            // Supprimer l'ancienne image si elle existe
            if ($emploi->image_url) {
                $oldPath = str_replace('/storage/', 'public/', $emploi->image_url);
                if (Storage::exists($oldPath)) {
                    Storage::delete($oldPath);
                }
            }

            // Stocker la nouvelle image
            $image = $request->file('image');
            $storedPath = $image->store('edt', 'public'); // stocké dans storage/app/public/edt
            $relativePath = '/storage/' . $storedPath;     // renvoie "/storage/edt/nom_fichier.jpg"

            // Enregistrement
            $emploi->image_url = $relativePath;
        }

        // $emploi->updated_by = $request->input('email');
        $emploi->save();

        return response()->json(['image_url' => $emploi->image_url], 200);
    }

    public function store(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            'semestre' => 'required|integer|in:1,2',
        ]);

        $image = $request->file('image');
        $storedPath = $image->store('edt', 'public');
        $relativePath = '/storage/' . $storedPath;  // "/storage/edt/..."

        $emploi = Edt::create([
            'image_url' => $relativePath,
            'semestre' => $request->semestre,
        ]);

        return response()->json($emploi, 201);
    }

    public function destroy($id)
    {
        $emploi = Edt::findOrFail($id);

        if ($emploi->image_url) {
            $imagePath = str_replace('/storage/', 'public/', $emploi->image_url);
            if (Storage::exists($imagePath)) {
                Storage::delete($imagePath);
            }
        }

        $emploi->delete();
        return response()->json(['message' => 'Image supprimée']);
    }
}


//php artisan storage:link (commande à ne pas oublier après déploiement)
