<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Ce que la personne a déjà lu dans la cloche de l'en-tête, gardé sur son compte :
 * la même chose est lue sur tous ses navigateurs et appareils.
 *   - annonces, notes, edt, cours : lu jusqu'à cette date (date de publication la plus récente vue) ;
 *   - lecons_vues : leçons ouvertes une à une.
 * Les dates ne font qu'avancer et les leçons vues s'ajoutent : un appareil en retard
 * (onglet resté ouvert) ne peut pas faire réapparaître ce qui a été lu ailleurs.
 */
class NotificationsLuesController extends Controller
{
    private const DATES = ['annonces', 'notes', 'edt', 'cours'];
    private const MAX_LECONS = 200; // les plus anciennes sont de toute façon déjà lues

    /** GET /api/notifications/lues */
    public function show(Request $request)
    {
        return response()->json($this->complet($request->user()->notifications_lues ?? []));
    }

    /** PUT /api/notifications/lues : seuls les éléments envoyés sont mis à jour */
    public function update(Request $request)
    {
        $valide = $request->validate(
            array_fill_keys(self::DATES, ['nullable', 'date']) + [
                'lecons_vues'   => ['nullable', 'array', 'max:' . self::MAX_LECONS],
                'lecons_vues.*' => ['integer'],
            ]
        );

        $user = $request->user();
        $lues = $this->complet($user->notifications_lues ?? []);

        foreach (self::DATES as $cle) {
            if (empty($valide[$cle])) {
                continue;
            }
            if (!$lues[$cle] || Carbon::parse($valide[$cle])->greaterThan(Carbon::parse($lues[$cle]))) {
                $lues[$cle] = $valide[$cle]; // gardée telle qu'envoyée : c'est une date du serveur, comparée telle quelle par l'application
            }
        }
        if (!empty($valide['lecons_vues'])) {
            $lues['lecons_vues'] = array_slice(
                array_values(array_unique([...array_map('intval', $valide['lecons_vues']), ...$lues['lecons_vues']])),
                0,
                self::MAX_LECONS
            );
        }

        $user->forceFill(['notifications_lues' => $lues])->save();

        return response()->json($lues);
    }

    private function complet(array $lues): array
    {
        // Toujours toutes les clés, même pour un compte qui n'a encore rien lu
        return array_merge(
            array_fill_keys(self::DATES, null) + ['lecons_vues' => []],
            array_intersect_key($lues, array_flip([...self::DATES, 'lecons_vues']))
        );
    }
}
