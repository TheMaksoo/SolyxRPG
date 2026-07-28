<?php

namespace App\Http\Controllers\Api;

use App\Models\Battle;
use App\Models\Character;
use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use App\Services\LeaderboardService;
use Illuminate\Http\Request;

class LeaderboardController extends Controller
{
    private const RANGES = ['all', 'season', 'weekly', 'daily'];
    private const SCOPES = ['global', 'guild', 'friends'];
    private const PER_PAGE = 10;

    public function __construct(private LeaderboardService $leaderboard) {}

    public function index(Request $request)
    {
        abort_unless(FeatureFlag::gate('leaderboard', $request->user()), 403, 'The Leaderboard is not currently available.');

        $character = $request->user()->character;

        $category = $request->query('category', 'trophies');
        if (! isset(LeaderboardService::CATEGORIES[$category])) {
            $category = 'trophies';
        }

        $range = $request->query('range', 'all');
        if (! in_array($range, self::RANGES, true)) {
            $range = 'all';
        }

        $scope = $request->query('scope', 'global');
        if (! in_array($scope, self::SCOPES, true)) {
            $scope = 'global';
        }

        $page = max(1, (int) $request->query('page', 1));

        $board = $this->leaderboard->board($category, $character, $range, $scope);
        $totalShown = count($board['rows']);
        $pageRows = array_slice($board['rows'], ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        $season = $this->leaderboard->currentSeason();

        // my_standing is the player's rank regardless of pagination — always populated, powers the "Your
        // Standing" rail card and the "Jump to me" page calculation. my_rank is the same data but
        // suppressed to null when the player is already visible on the current page, since that field is
        // what the table renders as a separate pinned row (showing it twice would be redundant).
        $myStanding = $board['my_rank'];
        $alreadyOnPage = $myStanding && collect($pageRows)->contains('character_id', $myStanding['character_id']);

        return response()->json([
            'leaderboard' => $pageRows,
            'my_rank' => $alreadyOnPage ? null : $myStanding,
            'my_standing' => $myStanding,
            'my_rank_page' => $myStanding ? (int) floor(($myStanding['rank'] - 1) / self::PER_PAGE) + 1 : null,
            'scope_empty_reason' => $board['scope_empty_reason'],
            'category' => $category,
            'categories' => LeaderboardService::CATEGORIES,
            'range' => $range,
            'scope' => $scope,
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'total_shown' => $totalShown,
            'total_players' => Character::count(),
            'season' => [
                'number' => $season->season_number,
                'name' => $season->name,
                'starts_at' => $season->starts_at,
                'ends_at' => $season->ends_at,
            ],
            'reward_tiers' => LeaderboardService::REWARD_TIERS,
            'hall_of_fame' => $this->leaderboard->hallOfFame(),
            'rivals' => $character ? $this->leaderboard->rivals($character, $category) : [],
        ]);
    }

    /** Bulk "my rank in every category" for the sidebar's per-category pills — one request instead of 18. */
    public function myRanks(Request $request)
    {
        abort_unless(FeatureFlag::gate('leaderboard', $request->user()), 403, 'The Leaderboard is not currently available.');

        $character = $request->user()->character;
        abort_unless($character, 404);

        return response()->json(['ranks' => $this->leaderboard->myRanksFor($character)]);
    }

    /** The 5 most-recent characters to walk into a battle, for the dashboard's "who's fighting right now"
     * rail card — same visual treatment as the Leaderboard card, just live-activity flavored. Excludes
     * the requesting player's own character (seeing yourself in your own feed isn't interesting) and only
     * looks at monster fights, not PvP, since PvpLiveMatch is a different table entirely. */
    public function recentBattles(Request $request)
    {
        $character = $request->user()->character;

        $rows = Battle::with(['character.user', 'monster'])
            ->when($character, fn ($q) => $q->where('character_id', '!=', $character->id))
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->filter(fn (Battle $b) => $b->character !== null)
            ->map(fn (Battle $b) => [
                'character_id' => $b->character->id,
                'name' => $b->character->name,
                'level' => $b->character->level,
                'base_class' => $b->character->base_class,
                'monster_name' => $b->monster?->name,
                'created_at' => $b->created_at,
            ])
            ->values();

        return response()->json(['recent_battles' => $rows]);
    }
}
