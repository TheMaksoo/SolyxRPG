<?php

namespace App\Http\Controllers\Api\Gm;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Dungeon;
use App\Models\Event;
use App\Models\Item;
use App\Models\Monster;
use App\Models\Pet;
use App\Models\Quest;
use App\Models\Recipe;
use App\Models\Skill;
use App\Models\Zone;
use App\Services\WikiSyncService;
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
        $row = $model::create($request->all());

        AuditLog::record($request->user()->id, 'gm.content.create', $resource, $row->id, $request->all());
        $this->syncWiki($resource, $row);

        return response()->json([$resource => $row], 201);
    }

    public function update(Request $request, string $resource, int $id)
    {
        $model = $this->resolve($resource);
        $row = $model::findOrFail($id);
        $row->update($request->all());

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

    private function syncWiki(string $resource, $row): void
    {
        if (isset(self::WIKI_SYNCED[$resource])) {
            $this->wiki->{self::WIKI_SYNCED[$resource][0]}($row);
        }
    }
}
