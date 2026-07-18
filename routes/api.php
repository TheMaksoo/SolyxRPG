<?php

use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BattleController;
use App\Http\Controllers\Api\BattlePassController;
use App\Http\Controllers\Api\CharacterController;
use App\Http\Controllers\Api\CraftingController;
use App\Http\Controllers\Api\DailyController;
use App\Http\Controllers\Api\DungeonController;
use App\Http\Controllers\Api\Gm\GmBroadcastController;
use App\Http\Controllers\Api\Gm\GmConfigController;
use App\Http\Controllers\Api\Gm\GmContentController;
use App\Http\Controllers\Api\Gm\GmFeatureFlagController;
use App\Http\Controllers\Api\Gm\GmPlayerController;
use App\Http\Controllers\Api\Gm\GmTicketController;
use App\Http\Controllers\Api\GuildController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\PetController;
use App\Http\Controllers\Api\QuestController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Api\SocialiteController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\VipController;
use App\Http\Controllers\Api\WikiController;
use App\Http\Controllers\Api\ZoneController;
use Illuminate\Support\Facades\Route;

Route::get('/wiki', [WikiController::class, 'index']);

// Auth
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/auth/{provider}/redirect', [SocialiteController::class, 'redirect'])->whereIn('provider', ['discord', 'google', 'apple']);
Route::get('/auth/{provider}/callback', [SocialiteController::class, 'callback'])->whereIn('provider', ['discord', 'google', 'apple']);

// Stripe webhook — no auth, verified by signature instead
Route::post('/store/webhook', [StoreController::class, 'webhook']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/announcements', [AnnouncementController::class, 'index']);

    Route::get('/character', [CharacterController::class, 'show']);
    Route::post('/character', [CharacterController::class, 'store']);
    Route::post('/character/attributes', [CharacterController::class, 'spendAttribute']);
    Route::post('/character/skills/{skill}', [CharacterController::class, 'unlockSkill']);
    Route::post('/character/profession', [CharacterController::class, 'chooseProfession']);

    Route::get('/zones', [ZoneController::class, 'index']);
    Route::post('/zones/{zone}/travel', [ZoneController::class, 'travel']);

    Route::get('/battle/enemies', [BattleController::class, 'enemies']);
    Route::post('/battle/start', [BattleController::class, 'start']);
    Route::get('/battle/{battle}', [BattleController::class, 'show']);
    Route::post('/battle/{battle}/action', [BattleController::class, 'action']);

    Route::get('/dungeons', [DungeonController::class, 'index']);
    Route::post('/dungeons/{dungeon}/enter', [DungeonController::class, 'enter']);

    Route::get('/shop', [ShopController::class, 'index']);
    Route::post('/shop/buy', [ShopController::class, 'buy']);

    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::post('/inventory/equip', [InventoryController::class, 'equip']);

    Route::get('/quests', [QuestController::class, 'index']);
    Route::post('/quests/{quest}/claim', [QuestController::class, 'claim']);

    Route::get('/crafting/recipes', [CraftingController::class, 'index']);
    Route::post('/crafting/{recipe}/craft', [CraftingController::class, 'craft']);

    Route::get('/pets', [PetController::class, 'index']);
    Route::post('/pets/{pet}/unlock', [PetController::class, 'unlock']);
    Route::post('/pets/{pet}/activate', [PetController::class, 'activate']);

    Route::get('/guild', [GuildController::class, 'index']);
    Route::post('/guild', [GuildController::class, 'store']);
    Route::post('/guild/{guild}/join', [GuildController::class, 'join']);
    Route::post('/guild/{guild}/message', [GuildController::class, 'message']);

    Route::get('/leaderboard', [LeaderboardController::class, 'index']);

    Route::get('/daily', [DailyController::class, 'index']);
    Route::post('/daily/claim', [DailyController::class, 'claim']);

    // Monetization
    Route::get('/store/gems', [StoreController::class, 'gems']);
    Route::post('/store/checkout', [StoreController::class, 'checkout']);
    Route::get('/battlepass', [BattlePassController::class, 'index']);
    Route::post('/battlepass/unlock', [BattlePassController::class, 'unlock']);
    Route::get('/vip', [VipController::class, 'index']);
    Route::post('/vip/subscribe', [VipController::class, 'subscribe']);

    // GM console
    Route::middleware('gm')->prefix('gm')->group(function () {
        Route::get('/{resource}', [GmContentController::class, 'index'])->where('resource', 'items|monsters|zones|dungeons|quests|skills|recipes|pets|events');
        Route::post('/{resource}', [GmContentController::class, 'store'])->where('resource', 'items|monsters|zones|dungeons|quests|skills|recipes|pets|events');
        Route::put('/{resource}/{id}', [GmContentController::class, 'update'])->where('resource', 'items|monsters|zones|dungeons|quests|skills|recipes|pets|events');
        Route::delete('/{resource}/{id}', [GmContentController::class, 'destroy'])->where('resource', 'items|monsters|zones|dungeons|quests|skills|recipes|pets|events');

        Route::get('/feature-flags', [GmFeatureFlagController::class, 'index']);
        Route::put('/feature-flags/{flag}', [GmFeatureFlagController::class, 'update']);

        Route::get('/config', [GmConfigController::class, 'index']);
        Route::put('/config/{key}', [GmConfigController::class, 'update']);

        Route::get('/players', [GmPlayerController::class, 'index']);
        Route::post('/players/{user}/grant', [GmPlayerController::class, 'grant']);
        Route::post('/players/{user}/ban', [GmPlayerController::class, 'ban']);

        Route::get('/tickets', [GmTicketController::class, 'index']);
        Route::post('/tickets/{ticket}/resolve', [GmTicketController::class, 'resolve']);

        Route::post('/broadcast', [GmBroadcastController::class, 'store']);
    });
});
