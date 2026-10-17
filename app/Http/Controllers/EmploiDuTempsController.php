<?php

namespace App\Http\Controllers;

use App\Models\Edt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Emplois du temps (images) par semestre.
 *
 * Les fichiers sont stockés sur le disque « public » (storage/app/public/edt) et servis par
 * le lien public/storage : la commande `php artisan storage:link` est indispensable
 * sur chaque installation (sans elle, les images répondent 404).
 * La base ne garde que le chemin public : /storage/edt/<fichier>.
 */
class EmploiDuTempsController extends Controller
{
    private const REGLES_IMAGE = 'required|image|mimes:jpeg,png,jpg,webp|max:5120';
    private const MESSAGES = [
        'image.required' => 'Veuillez choisir une image.',
        'image.image'    => 'Le fichier doit être une image (JPG, PNG ou WEBP).',
        'image.mimes'    => 'Formats acceptés : JPG, PNG ou WEBP.',
        'image.max'      => "L'image ne doit pas dépasser 5 Mo.",
    ];

    public function index()
    {
        return Edt::orderBy('id', 'asc')->get();
    }

    public function updateImage(Request $request, $id)
    {
        $request->validate(['image' => self::REGLES_IMAGE], self::MESSAGES);

        $emploi = Edt::findOrFail($id);
        $ancienne = $emploi->image_url;

        $emploi->image_url = $this->enregistrer($request);
        $emploi->updated_by = $request->user()->email;
        $emploi->save();

        $this->supprimerFichier($ancienne);

        return response()->json(['image_url' => $emploi->image_url], 200);
    }

    public function store(Request $request)
    {
        $request->validate([
            'image'    => self::REGLES_IMAGE,
            'semestre' => 'required|integer|in:1,2',
        ], self::MESSAGES);

        $emploi = Edt::create([
            'image_url'  => $this->enregistrer($request),
            'semestre'   => $request->semestre,
            'updated_by' => $request->user()->email,
        ]);

        return response()->json($emploi, 201);
    }

    public function destroy($id)
    {
        $emploi = Edt::findOrFail($id);
        $this->supprimerFichier($emploi->image_url);
        $emploi->delete();

        return response()->json(['message' => 'Image supprimée']);
    }

    /** Stocke l'image dans storage/app/public/edt et renvoie son chemin public « /storage/edt/… » */
    private function enregistrer(Request $request): string
    {
        return '/storage/' . $request->file('image')->store('edt', 'public');
    }

    private function supprimerFichier(?string $cheminPublic): void
    {
        if (!$cheminPublic || !str_starts_with($cheminPublic, '/storage/')) {
            return;
        }
        Storage::disk('public')->delete(substr($cheminPublic, strlen('/storage/')));
    }
}
