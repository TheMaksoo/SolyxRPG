<?php

use App\Http\Controllers\Api\AchievementController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AutoBattleController;
use App\Http\Controllers\Api\AutoGatherController;
use App\Http\Controllers\Api\BattleController;
use App\Http\Controllers\Api\BattlePassController;
use App\Http\Controllers\Api\CharacterController;
use App\Http\Controllers\Api\ClassProgressionController;
use App\Http\Controllers\Api\CosmeticController;
use App\Http\Controllers\Api\CraftingController;
use App\Http\Controllers\Api\DailyController;
use App\Http\Controllers\Api\DirectMessageController;
use App\Http\Controllers\Api\DungeonController;
use App\Http\Controllers\Api\FounderPackController;
use App\Http\Controllers\Api\FriendController;
use App\Http\Controllers\Api\Gm\GmAuditLogController;
use App\Http\Controllers\Api\Gm\GmArtisanController;
use App\Http\Controllers\Api\Gm\GmBroadcastController;
use App\Http\Controllers\Api\Gm\GmConfigController;
use App\Http\Controllers\Api\Gm\GmContentController;
use App\Http\Controllers\Api\Gm\GmFeatureFlagController;
use App\Http\Controllers\Api\Gm\GmAnalyticsController;
use App\Http\Controllers\Api\Gm\GmErrorLogController;
use App\Http\Controllers\Api\Gm\GmMetricsController;
use App\Http\Controllers\Api\Gm\GmPlayerController;
use App\Http\Controllers\Api\Gm\GmRevenueController;
use App\Http\Controllers\Api\Gm\GmProgressionController;
use App\Http\Controllers\Api\Gm\GmTicketController;
use App\Http\Controllers\Api\GuildController;
use App\Http\Controllers\Api\GuildWarController;
use App\Http\Controllers\Api\InboxController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\ChangelogController;
use App\Http\Controllers\Api\KnownBugController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\MarketplaceController;
use App\Http\Controllers\Api\NavBadgeController;
use App\Http\Controllers\Api\PartyController;
use App\Http\Controllers\Api\PetController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PvpController;
use App\Http\Controllers\Api\QuestController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Api\SkillController;
use App\Http\Controllers\Api\SocialiteController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\StatusController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\TradeSkillController;
use App\Http\Controllers\Api\VipController;
use App\Http\Controllers\Api\WikiController;
use App\Http\Controllers\Api\WorldChatController;
use App\Http\Controllers\Api\ZoneController;
use Illuminate\Support\Facades\Route;

Route::get('/wiki', [WikiController::class, 'index']);
Route::get('/stats/public', [StatsController::class, 'public']);

// OAuth routes need explicit 'web' middleware because they are NOT regular AJAX calls from the
// SPA. The redirect is a full browser navigation (no Origin header), and the callback arrives
// from the OAuth provider whose domain is not in SANCTUM_STATEFUL_DOMAINS. Without 'web' here,
// Sanctum's EnsureFrontendRequestsAreStateful would not fire for the callback, and Auth::login()
// would have no session to write the authenticated user into.
Route::middleware('web')->group(function () {
    Route::get('/auth/{provider}/redirect', [SocialiteController::class, 'redirect'])->whereIn('provider', ['discord', 'google']);
    Route::get('/auth/{provider}/callback', [SocialiteController::class, 'callback'])->whereIn('provider', ['discord', 'google']);
});

// Login-family routes and /me are AJAX calls from the SPA (they carry an Origin header that
// matches SANCTUM_STATEFUL_DOMAINS). statefulApi() in bootstrap/app.php appends
// EnsureFrontendRequestsAreStateful to the 'api' group, and that middleware already wraps every
// SPA request in its own inner pipeline of EncryptCookies → StartSession → VerifyCsrfToken.
//
// Do NOT also wrap these routes in 'web'. That runs EncryptCookies a second time: the first pass
// (inside EnsureFrontendRequestsAreStateful) decrypts the session cookie into a plain session ID;
// the second pass (from the 'web' group) then tries to decrypt that already-plaintext value,
// throws DecryptException, and sets the cookie to null. The subsequent StartSession sees no
// cookie, creates a fresh empty session, and every auth guard check returns 401 — this is the
// exact "Could not sign in with that provider" / post-login 401 bug.
//
// All other API routes further below also omit 'web' for the same reason. The session/cookie
// pipeline that 'web' would bring is already present via EnsureFrontendRequestsAreStateful.
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware(['throttle:login', 'throttle:20,1']);
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:6,1');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:6,1');
Route::get('/me', [AuthController::class, 'me'])->middleware(['auth:sanctum', 'not-banned']);

// Stripe webhook — no auth, verified by signature instead
Route::post('/store/webhook', [StoreController::class, 'webhook']);

Route::middleware(['auth:sanctum', 'not-banned'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/me/tester-mode', [AuthController::class, 'toggleTesterMode']);
    Route::put('/me/preferences', [AuthController::class, 'updatePreferences']);
    Route::delete('/me', [AuthController::class, 'deleteAccount']);
    Route::get('/announcements', [AnnouncementController::class, 'index']);
    Route::get('/nav-badges', [NavBadgeController::class, 'index']);
    Route::get('/status/check', [StatusController::class, 'check']);

    Route::get('/character', [CharacterController::class, 'show']);
    Route::post('/character/save-tick', [CharacterController::class, 'saveTick'])->middleware('throttle:regen-sync');
    Route::get('/character/activity', [CharacterController::class, 'activity']);
    Route::post('/character', [CharacterController::class, 'store']);
    Route::post('/character/attributes', [CharacterController::class, 'spendAttribute']);
    Route::post('/character/skills/{skill}', [CharacterController::class, 'unlockSkill']);
    Route::post('/character/profession', [CharacterController::class, 'chooseProfession']);
    Route::post('/character/tutorial/dismiss', [CharacterController::class, 'dismissTutorial']);
    Route::post('/character/tutorial/restart', [CharacterController::class, 'restartTutorial']);

    Route::get('/characters', [CharacterController::class, 'index']);
    Route::post('/characters/{character}/select', [CharacterController::class, 'select']);
    Route::delete('/characters/{character}', [CharacterController::class, 'destroy']);
    Route::post('/characters/slots/unlock', [CharacterController::class, 'unlockSlot']);
    Route::get('/characters/{character}/profile', [CharacterController::class, 'publicProfile']);

    Route::get('/skills', [SkillController::class, 'index']);
    Route::get('/class-progressions', [ClassProgressionController::class, 'index']);

    Route::get('/zones', [ZoneController::class, 'index']);
    Route::post('/zones/{zone}/travel', [ZoneController::class, 'travel']);

    Route::get('/chat/world', [WorldChatController::class, 'index']);
    Route::post('/chat/world', [WorldChatController::class, 'send'])->middleware('throttle:20,1');

    Route::get('/battle/active', [BattleController::class, 'active']);
    Route::post('/battle/walk', [BattleController::class, 'walk']);
    Route::get('/battle/{battle}', [BattleController::class, 'show']);
    Route::post('/battle/{battle}/action', [BattleController::class, 'action']);

    Route::get('/auto-battle', [AutoBattleController::class, 'show']);
    Route::post('/auto-battle/purchase', [AutoBattleController::class, 'purchase']);

    Route::get('/auto-gather', [AutoGatherController::class, 'show']);
    Route::post('/auto-gather/purchase', [AutoGatherController::class, 'purchase']);
    Route::post('/auto-gather/switch', [AutoGatherController::class, 'switch']);

    Route::get('/dungeons', [DungeonController::class, 'index']);
    Route::post('/dungeons/{dungeon}/enter', [DungeonController::class, 'enter']);

    Route::get('/shop', [ShopController::class, 'index']);
    Route::post('/shop/buy', [ShopController::class, 'buy']);

    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::post('/inventory/equip', [InventoryController::class, 'equip']);
    Route::post('/inventory/unequip', [InventoryController::class, 'unequip']);
    Route::post('/inventory/scrap', [InventoryController::class, 'scrap']);
    Route::post('/inventory/use', [InventoryController::class, 'use']);
    Route::post('/inventory/repair', [InventoryController::class, 'repair']);

    Route::get('/quests', [QuestController::class, 'index']);
    Route::post('/quests/{quest}/claim', [QuestController::class, 'claim']);

    Route::get('/crafting/recipes', [CraftingController::class, 'index']);
    Route::post('/crafting/{recipe}/craft', [CraftingController::class, 'craft']);
    Route::get('/crafting/queue', [CraftingController::class, 'queue']);
    Route::post('/crafting/jobs/{job}/collect', [CraftingController::class, 'collect']);

    Route::get('/trade-skills', [TradeSkillController::class, 'index']);
    Route::get('/trade-skills/cooldowns', [TradeSkillController::class, 'cooldowns']);
    Route::post('/trade-skills/gather-batch', [TradeSkillController::class, 'gatherBatch']);
    Route::post('/trade-skills/{skillKey}/gather', [TradeSkillController::class, 'gather']);

    Route::get('/pets', [PetController::class, 'index']);
    Route::post('/pets/{pet}/unlock', [PetController::class, 'unlock']);
    Route::post('/pets/{pet}/activate', [PetController::class, 'activate']);
    Route::post('/pets/{pet}/rank-up', [PetController::class, 'rankUp']);

    Route::get('/guild', [GuildController::class, 'index']);
    Route::post('/guild', [GuildController::class, 'store']);
    Route::post('/guild/{guild}/join', [GuildController::class, 'join']);
    Route::post('/guild/{guild}/message', [GuildController::class, 'message']);
    Route::post('/guild/{guild}/deposit', [GuildController::class, 'deposit']);
    Route::post('/guild/{guild}/withdraw', [GuildController::class, 'withdraw']);
    Route::post('/guild/{guild}/upgrades/purchase', [GuildController::class, 'purchaseUpgrade']);
    Route::post('/guild/{guild}/members/{target}/promote', [GuildController::class, 'promote']);
    Route::post('/guild/{guild}/members/{target}/kick', [GuildController::class, 'kick']);
    Route::post('/guild/{guild}/leave', [GuildController::class, 'leave']);

    Route::get('/guild-war/status', [GuildWarController::class, 'status']);
    Route::post('/guild-war/{guild}/enter', [GuildWarController::class, 'enter']);
    Route::post('/guild-war/battles/{battle}/resolve', [GuildWarController::class, 'resolve']);

    Route::get('/leaderboard', [LeaderboardController::class, 'index']);
    Route::get('/leaderboard/my-ranks', [LeaderboardController::class, 'myRanks']);
    Route::get('/leaderboard/recent-battles', [LeaderboardController::class, 'recentBattles']);
    Route::get('/supporters', [LeaderboardController::class, 'supporters']);

    Route::get('/market', [MarketplaceController::class, 'index']);
    Route::get('/market/mine', [MarketplaceController::class, 'mine']);
    Route::post('/market', [MarketplaceController::class, 'store']);
    Route::post('/market/{listing}/buy', [MarketplaceController::class, 'buy']);
    Route::post('/market/{listing}/cancel', [MarketplaceController::class, 'cancel']);

    Route::get('/daily', [DailyController::class, 'index']);
    Route::post('/daily/claim', [DailyController::class, 'claim']);

    Route::get('/achievements', [AchievementController::class, 'index']);

    Route::get('/cosmetics', [CosmeticController::class, 'index']);
    Route::post('/cosmetics/{cosmetic}/unlock', [CosmeticController::class, 'unlock']);
    Route::post('/cosmetics/{cosmetic}/equip', [CosmeticController::class, 'equip']);

    Route::get('/profile/panels', [ProfileController::class, 'panels']);
    Route::get('/profile/history', [ProfileController::class, 'history']);
    Route::patch('/profile/showcase', [ProfileController::class, 'updateShowcase']);
    Route::patch('/profile/meta', [ProfileController::class, 'updateMeta']);
    Route::patch('/profile/rename', [ProfileController::class, 'rename']);
    Route::patch('/profile/privacy', [ProfileController::class, 'updatePrivacy']);

    Route::get('/friends', [FriendController::class, 'index']);
    Route::post('/friends/{target}/request', [FriendController::class, 'request']);
    Route::post('/friends/requests/{friendship}/accept', [FriendController::class, 'accept']);
    Route::post('/friends/requests/{friendship}/decline', [FriendController::class, 'decline']);
    Route::delete('/friends/{target}', [FriendController::class, 'remove']);
    Route::post('/friends/{target}/favorite', [FriendController::class, 'toggleFavorite']);
    Route::get('/friends/{target}/messages', [DirectMessageController::class, 'thread']);
    Route::post('/friends/{target}/messages', [DirectMessageController::class, 'send']);

    Route::get('/party', [PartyController::class, 'index']);
    Route::post('/party', [PartyController::class, 'store']);
    Route::post('/party/invite/{target}', [PartyController::class, 'invite']);
    Route::post('/party/invites/{invite}/accept', [PartyController::class, 'acceptInvite']);
    Route::post('/party/invites/{invite}/decline', [PartyController::class, 'declineInvite']);
    Route::post('/party/message', [PartyController::class, 'message']);
    Route::post('/party/leave', [PartyController::class, 'leave']);
    Route::post('/party/kick/{target}', [PartyController::class, 'kick']);

    Route::get('/pvp', [PvpController::class, 'index']);
    Route::post('/pvp/queue/join', [PvpController::class, 'queueJoin']);
    Route::post('/pvp/queue/leave', [PvpController::class, 'queueLeave']);
    Route::get('/pvp/queue/status', [PvpController::class, 'queueStatus']);
    Route::get('/pvp/live/{match}', [PvpController::class, 'liveShow']);
    Route::post('/pvp/live/{match}/action', [PvpController::class, 'liveAction']);
    Route::post('/pvp/live/{match}/forfeit', [PvpController::class, 'liveForfeit']);

    Route::get('/inbox', [InboxController::class, 'index']);
    Route::post('/inbox/{mail}/read', [InboxController::class, 'read']);
    Route::post('/inbox/{mail}/dismiss', [InboxController::class, 'dismiss']);

    Route::get('/known-bugs', [KnownBugController::class, 'index']);

    Route::get('/changelog', [ChangelogController::class, 'index']);
    Route::get('/changelog/current', [ChangelogController::class, 'current']);

    Route::get('/referrals', [ReferralController::class, 'index']);
    Route::post('/referrals/copy', [ReferralController::class, 'trackCopy']);

    Route::get('/support-tickets', [SupportTicketController::class, 'index']);
    Route::post('/support-tickets', [SupportTicketController::class, 'store']);
    Route::post('/support-tickets/{ticket}/messages', [SupportTicketController::class, 'sendMessage']);

    // Monetization
    Route::get('/store/gems', [StoreController::class, 'gems']);
    Route::get('/store/gem-sinks', [StoreController::class, 'gemSinks']);
    Route::post('/store/checkout', [StoreController::class, 'checkout']);
    Route::get('/battlepass', [BattlePassController::class, 'index']);
    Route::post('/battlepass/unlock', [BattlePassController::class, 'unlock']);
    Route::post('/battlepass/claim', [BattlePassController::class, 'claim']);
    Route::post('/battlepass/claim-all', [BattlePassController::class, 'claimAll']);
    Route::get('/vip', [VipController::class, 'index']);
    Route::post('/vip/subscribe', [VipController::class, 'subscribe']);
    Route::post('/vip/cancel', [VipController::class, 'cancel']);
    Route::post('/vip/resume', [VipController::class, 'resume']);
    Route::post('/vip/lifetime', [VipController::class, 'purchaseLifetime']);
    Route::get('/founders', [FounderPackController::class, 'index']);
    Route::post('/founders/purchase', [FounderPackController::class, 'purchase']);

    // GM console
    Route::middleware('gm')->prefix('gm')->group(function () {
        Route::get('/{resource}', [GmContentController::class, 'index'])->where('resource', 'items|monsters|zones|dungeons|quests|skills|recipes|pets|events|cosmetics|known_bugs|changelogs');
        Route::post('/{resource}', [GmContentController::class, 'store'])->where('resource', 'items|monsters|zones|dungeons|quests|skills|recipes|pets|events|cosmetics|known_bugs|changelogs');
        Route::put('/{resource}/{id}', [GmContentController::class, 'update'])->where('resource', 'items|monsters|zones|dungeons|quests|skills|recipes|pets|events|cosmetics|known_bugs|changelogs');
        Route::delete('/{resource}/{id}', [GmContentController::class, 'destroy'])->where('resource', 'items|monsters|zones|dungeons|quests|skills|recipes|pets|events|cosmetics|known_bugs|changelogs');

        Route::get('/feature-flags', [GmFeatureFlagController::class, 'index']);
        Route::put('/feature-flags/{flag}', [GmFeatureFlagController::class, 'update']);

        Route::get('/config', [GmConfigController::class, 'index']);
        Route::put('/config/{key}', [GmConfigController::class, 'update']);

        Route::get('/players', [GmPlayerController::class, 'index']);
        Route::post('/players/{user}/grant', [GmPlayerController::class, 'grant']);
        Route::post('/players/{user}/ban', [GmPlayerController::class, 'ban']);
        Route::post('/players/{user}/mail', [GmPlayerController::class, 'mail']);
        Route::put('/players/{user}/edit', [GmPlayerController::class, 'update']);
        Route::post('/players/{user}/clear-stuck-state', [GmPlayerController::class, 'clearStuckState']);

        Route::get('/metrics', [GmMetricsController::class, 'index']);
        Route::get('/revenue', [GmRevenueController::class, 'index']);
        Route::get('/purchases', [GmRevenueController::class, 'purchases']);
        Route::get('/analytics', [GmAnalyticsController::class, 'index']);
        Route::get('/errors', [GmErrorLogController::class, 'index']);
        Route::post('/errors/{errorLog}/archive', [GmErrorLogController::class, 'archive']);

        Route::get('/tickets', [GmTicketController::class, 'index']);
        Route::post('/tickets/{ticket}/resolve', [GmTicketController::class, 'resolve']);
        Route::post('/tickets/{ticket}/messages', [GmTicketController::class, 'sendMessage']);

        Route::post('/broadcast', [GmBroadcastController::class, 'store']);

        Route::get('/audit-log', [GmAuditLogController::class, 'index']);

        Route::get('/xp-curve', [GmProgressionController::class, 'xpCurve']);
        Route::get('/battle-pass-curve', [GmProgressionController::class, 'battlePassCurve']);

        Route::get('/artisan-commands', [GmArtisanController::class, 'index']);
        Route::get('/artisan-commands/seeders', [GmArtisanController::class, 'seeders']);
        Route::post('/artisan-commands/execute', [GmArtisanController::class, 'execute']);
    });
});
