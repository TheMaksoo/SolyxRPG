<?php

use Illuminate\Support\Facades\Route;

// Served via a route (not a static public/ file) so it always gets the correct
// application/manifest+json content-type regardless of the host webserver's own
// mime.types config — some Apache setups don't map the .webmanifest extension.
Route::get('/manifest.webmanifest', function () {
    return response()->json([
        'name' => 'Solyx RPG',
        'short_name' => 'Solyx',
        'description' => 'A free browser RPG with classes, dungeons, crafting, guilds and PvP.',
        'start_url' => '/',
        'scope' => '/',
        'display' => 'standalone',
        'orientation' => 'any',
        'background_color' => '#0b0b0c',
        'theme_color' => '#0b0b0c',
        'icons' => [
            ['src' => '/images/pwa-icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/images/pwa-icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ],
    ])->header('Content-Type', 'application/manifest+json');
});

Route::view('/{any}', 'welcome')->where('any', '.*');
