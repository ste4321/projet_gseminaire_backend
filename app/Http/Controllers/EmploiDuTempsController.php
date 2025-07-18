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
        $emploi = Edt::findOrFail($id);
    
        if ($request->hasFile('image')) {
            // Supprimer l'ancienne image (gère les dossiers edt et emplois)
            if ($emploi->image_url) {
                $oldPath = str_replace('/storage/', 'public/', $emploi->image_url);
                if (Storage::exists($oldPath)) {
                    Storage::delete($oldPath);
                } else {
                }
            }
    
            // Stocker la nouvelle image dans le dossier edt
            $image = $request->file('image');
            $path = $image->store('edt', 'public');
            $url = '/storage/' . $path;
    
            // Enregistrement
            $emploi->image_url = $url;
            $emploi->updated_by = $request->input('email');
            $emploi->save();
    
            return response()->json(['image_url' => $url], 200);
        }
    
        return response()->json(['error' => 'Aucune image fournie'], 400);
    }

    public function store(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            'semestre' => 'required|integer|in:1,2',
        ]);

        if ($request->hasFile('image')) {
            // Stocker dans le dossier edt au lieu d'emplois
            $path = $request->file('image')->store('edt', 'public');
            $emploi = Edt::create([
                'image_url' => '/storage/' . $path,
                'semestre' => $request->semestre,
            ]);

            return response()->json($emploi, 201);
        }

        return response()->json(['error' => 'Aucune image trouvée.'], 400);
    }

    public function destroy($id)
    {
        $emploi = Edt::findOrFail($id);
        
        // Supprimer l'image du stockage avant de supprimer l'enregistrement
        if ($emploi->image_url) {
            $imagePath = str_replace('/storage/', 'public/', $emploi->image_url);
            if (Storage::exists($imagePath)) {
                Storage::delete($imagePath);
            } else {
            }
        }
        
        $emploi->delete();
        return response()->json(['message' => 'Image supprimée']);
    }
}