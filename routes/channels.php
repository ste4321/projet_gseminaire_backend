<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

// Canal privé de chaque utilisateur : notifications en direct (ex. annonces, voir App\Events\AnnonceMiseAJour)
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Statut « En ligne » des comptes (App\Events\PresenceCompte) : administrateurs uniquement,
// jamais pendant une consultation de l'espace d'un autre utilisateur
Broadcast::channel('comptes', function ($user) {
    return $user->role === 'admin'
        && !in_array('consultation', $user->currentAccessToken()?->abilities ?? [], true);
});
