<?php

namespace App\Http\Controllers\Api\Gm;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Cosmetic;
use App\Models\Dungeon;
use App\Models\Event;
use App\Models\Item;
use App\Models\KnownBug;
use App\Models\Monster;
use App\Models\Pet;
use App\Models\Quest;
use App\Models\Recipe;
use App\Models\Skill;
use App\Models\Zone;
use App\Services\WikiSyncService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class GmContentController extends Controller
{
    private const RESOURCES = [
        'items' => Item::class,
        'monsters' => Monster::class,
        'zones' => Zone::class,
        'dungeons' => Dungeon::class,
        'quests' => Quest::class,
        'skills' => Skill::class,
        'recipes' => Recipe::class,
        'pets' => Pet::class,
        'events' => Event::class,
        'cosmetics' => Cosmetic::class,
        'known_bugs' => KnownBug::class,
    ];

    /** Minimal required-field guard per resource — catches the most common blank-required-column 500s before they hit the DB. */
    private const REQUIRED_FIELDS = [
        'items' => ['key', 'name', 'type', 'rarity'],
        'monsters' => ['key', 'name'],
        'zones' => ['key', 'name', 'danger'],
        'dungeons' => ['key', 'name', 'difficulty'],
        'quests' => ['name', 'type'],
        'skills' => ['branch', 'key', 'name'],
        'recipes' => ['name', 'result_item_id'],
        'pets' => ['key', 'name'],
        'events' => ['name', 'type'],
        'cosmetics' => ['key', 'type', 'name', 'value', 'rarity'],
        'known_bugs' => ['title', 'description'],
    ];

    /** resource key => [WikiSyncService sync method, wiki source_type] */
    private const WIKI_SYNCED = [
        'items' => ['syncItem', 'item'],
        'monsters' => ['syncMonster', 'monster'],
        'pets' => ['syncPet', 'pet'],
    ];

    public function __construct(private WikiSyncService $wiki) {}

    public function index(Request $request, string $resource)
    {
        $model = $this->resolve($resource);

        return response()->json([$resource => $model::orderBy('id')->get()]);
    }

    public function store(Request $request, string $resource)
    {
        $model = $this->resolve($resource);
        $this->validateRequired($request, $resource);

        try {
            $row = $model::create($request->all());
        } catch (QueryException $e) {
            return response()->json(['message' => $this->friendlyDbError($e, $resource)], 422);
        }

        AuditLog::record($request->user()->id, 'gm.content.create', $resource, $row->id, $request->all());
        $this->syncWiki($resource, $row);

        return response()->json([$resource => $row], 201);
    }

    public function update(Request $request, string $resource, int $id)
    {
        $model = $this->resolve($resource);
        $this->validateRequired($request, $resource);
        $row = $model::findOrFail($id);

        try {
            $row->update($request->all());
        } catch (QueryException $e) {
            return response()->json(['message' => $this->friendlyDbError($e, $resource)], 422);
        }

        AuditLog::record($request->user()->id, 'gm.content.update', $resource, $row->id, $request->all());
        $this->syncWiki($resource, $row->fresh());

        return response()->json([$resource => $row->fresh()]);
    }

    public function destroy(Request $request, string $resource, int $id)
    {
        $model = $this->resolve($resource);
        $row = $model::findOrFail($id);
        $row->delete();

        AuditLog::record($request->user()->id, 'gm.content.delete', $resource, $id);
        if (isset(self::WIKI_SYNCED[$resource])) {
            $this->wiki->removeSource(self::WIKI_SYNCED[$resource][1], $id);
        }

        return response()->json(['message' => 'Deleted.']);
    }

    private function resolve(string $resource): string
    {
        abort_unless(isset(self::RESOURCES[$resource]), 404, 'Unknown content resource.');

        return self::RESOURCES[$resource];
    }

    private function validateRequired(Request $request, string $resource): void
    {
        $rules = array_fill_keys(self::REQUIRED_FIELDS[$resource] ?? [], ['required']);
        if ($rules) {
            $request->validate($rules);
        }
    }

    /** Turns a raw DB exception into an actionable message instead of leaking SQL/a bare 500. */
    private function friendlyDbError(QueryException $e, string $resource): string
    {
        if (str_contains($e->getMessage(), 'Duplicate entry')) {
            return "A {$resource} record with that key/name already exists — choose a unique key.";
        }
        if (str_contains($e->getMessage(), 'Data truncated') || str_contains($e->getMessage(), "doesn't have a default value")) {
            return 'One of the dropdown fields (e.g. type/rarity/difficulty) is blank or not a valid option.';
        }

        return 'Could not save — check that referenced IDs (item/monster/zone) exist and required fields are filled in.';
    }

    private function syncWiki(string $resource, $row): void
    {
        if (isset(self::WIKI_SYNCED[$resource])) {
            $this->wiki->{self::WIKI_SYNCED[$resource][0]}($row);
        }
    }
}
