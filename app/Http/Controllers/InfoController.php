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
        return Info::create($request->all());
    }

    public function update(Request $request, $id)
    {
        $info = Info::findOrFail($id);
        $info->update($request->all());
        return $info;
    }

    public function destroy($id)
    {
        $info = Info::findOrFail($id);
        $info->delete();
        return response()->json(['message' => 'Supprimé avec succès']);
    }
}
