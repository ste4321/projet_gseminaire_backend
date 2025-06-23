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

    public function store(Request $request)
    {
        $request->validate([
            'image_url' => 'required|string',
            'semestre' => 'required|in:1,2',
        ]);

        return Edt::create($request->all());
    }

    // public function updateImage(Request $request, $id)
    // {
    //     $emploi = Edt::findOrFail($id);

    //     if ($request->hasFile('image')) {
    //         // Supprimer l'ancienne image si elle existe
    //         if ($emploi->image_url) {
    //             // Transforme /storage/edt/xxx.jpg → edt/xxx.jpg
    //             $oldPath = str_replace('/storage/', '', $emploi->image_url);

    //             // Supprimer avec le disque "public" (storage/app/public)
    //             if (Storage::disk('public')->exists($oldPath)) {
    //                 Storage::disk('public')->delete($oldPath);
    //             }
    //         }

    //         // Stocker dans storage/app/public/edt
    //         $image = $request->file('image');
    //         $path = $image->store('edt', 'public'); // chemin ex: edt/yyy.jpg

    //         // Générer l'URL visible publiquement
    //         $url = '/storage/' . $path;

    //         // Mise à jour en base
    //         $emploi->image_url = $url;
    //         $emploi->save();

    //         return response()->json(['image_url' => $url], 200);
    //     }

    //     return response()->json(['error' => 'Aucune image fournie'], 400);
    // }
    public function updateImage(Request $request, $id)
    {
        $emploi = Edt::findOrFail($id);
    
        if ($request->hasFile('image')) {
            // Supprimer l'ancienne image
            if ($emploi->image_url) {
                $oldPath = str_replace('/storage/', 'public/', $emploi->image_url);
                if (Storage::exists($oldPath)) {
                    Storage::delete($oldPath);
                }
            }
    
            $image = $request->file('image');
            $path = $image->store('edt', 'public');
            $url = '/storage/' . $path;
    
            // Enregistrement
            $emploi->image_url = $url;
            $emploi->updated_by = $request->input('email'); // <- on ajoute l'email ici
            $emploi->save();
    
            return response()->json(['image_url' => $url], 200);
        }
    
        return response()->json(['error' => 'Aucune image fournie'], 400);
    }
    

//------------------------------------------------------------------
    public function destroy($id)
    {
        $emploi = Edt::findOrFail($id);
        $emploi->delete();
        return response()->json(['message' => 'Image supprimée']);
    }
}

