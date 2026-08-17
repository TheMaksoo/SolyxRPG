<?php

namespace App\Http\Controllers\Api\Gm;

use App\Http\Controllers\Controller;
use App\Models\ArtAsset;
use App\Models\AuditLog;
use App\Models\Dungeon;
use App\Models\Item;
use App\Models\Monster;
use App\Models\Skill;
use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Backs the Art Studio (artist-facing) and the GM Console's Art Review tab. There is deliberately no
 * table listing every "thing that needs art" — that list is derived live from each content table's own
 * `sprite` column plus, for monsters only, which of the 4 grade files actually exist on the public disk
 * (see doneGrades()). art_assets only holds actual submissions (one row per upload attempt), so history
 * survives resubmits/rejections without needing a separate "needed" row to exist first. */
class GmArtController extends Controller
{
    private const ENTITY_MODELS = [
        'monsters' => Monster::class,
        'items' => Item::class,
        'skills' => Skill::class,
        'zones' => Zone::class,
        'dungeons' => Dungeon::class,
    ];

    private const GRADES = ['common', 'elite', 'champion', 'legendary'];

    private const MAX_UPLOAD_KB = 4096;

    public function board(Request $request)
    {
        $latest = $this->latestAssetsBySlot();
        $slots = [];

        foreach (self::ENTITY_MODELS as $type => $modelClass) {
            $query = $this->enabledScope($type, $modelClass::query())->orderBy('name');
            if ($type === 'monsters') {
                $query->with('zone');
            }
            foreach ($query->get() as $row) {
                if ($type === 'monsters') {
                    $grades = [];
                    $allDone = true;
                    foreach (self::GRADES as $grade) {
                        $status = $this->gradeStatus($row, $grade, $latest);
                        if ($status !== 'approved') {
                            $allDone = false;
                        }
                        $grades[] = ['grade' => $grade, 'status' => $status, 'asset_id' => $latest["monsters:{$row->id}:{$grade}"]->id ?? null];
                    }
                    $slots[] = $this->slotRow($type, $row, null, $grades, $allDone);
                } else {
                    $status = $this->singleStatus($type, $row, $latest);
                    $key = "{$type}:{$row->id}:";
                    $slots[] = $this->slotRow($type, $row, ['status' => $status, 'asset_id' => $latest[$key]->id ?? null], null, $status === 'approved');
                }
            }
        }

        return response()->json(['slots' => $slots]);
    }

    public function mine(Request $request)
    {
        $submissions = ArtAsset::where('submitted_by', $request->user()->id)
            ->orderByDesc('id')
            ->with('reviewer')
            ->get()
            ->map(fn (ArtAsset $a) => $this->submissionRow($a));

        return response()->json(['submissions' => $submissions]);
    }

    public function submit(Request $request)
    {
        $data = $request->validate([
            'entity_type' => ['required', 'string', 'in:'.implode(',', array_keys(self::ENTITY_MODELS))],
            'entity_id' => ['required', 'integer'],
            'grade' => ['nullable', 'string', 'in:'.implode(',', self::GRADES)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'file' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:'.self::MAX_UPLOAD_KB],
        ]);

        $modelClass = self::ENTITY_MODELS[$data['entity_type']];
        $row = $modelClass::findOrFail($data['entity_id']);
        $grade = $data['entity_type'] === 'monsters' ? ($data['grade'] ?? 'common') : null;

        $filename = $data['entity_id'].($grade ? "_{$grade}" : '').'_'.time().'.'.$request->file('file')->getClientOriginalExtension();
        $path = $request->file('file')->storeAs("art-submissions/{$data['entity_type']}", $filename, 'local');
        $meta = $this->readImageMeta(Storage::disk('local')->path($path));

        $asset = ArtAsset::create([
            'entity_type' => $data['entity_type'],
            'entity_id' => $row->id,
            'entity_label' => $row->name.($grade ? ' — '.ucfirst($grade) : ''),
            'grade' => $grade,
            'upload_path' => $path,
            'status' => 'pending',
            'notes' => $data['notes'] ?? null,
            'submitted_by' => $request->user()->id,
            ...$meta,
        ]);

        AuditLog::record($request->user()->id, 'gm.art.submit', $data['entity_type'], $row->id, ['grade' => $grade, 'art_asset_id' => $asset->id]);

        return response()->json(['submission' => $this->submissionRow($asset)], 201);
    }

    public function preview(Request $request, int $id)
    {
        $asset = ArtAsset::findOrFail($id);
        abort_unless(Storage::disk('local')->exists($asset->upload_path), 404, 'Upload no longer available.');

        return response()->file(Storage::disk('local')->path($asset->upload_path));
    }

    public function coverage(Request $request)
    {
        $latest = $this->latestAssetsBySlot();
        $coverage = [];

        foreach (self::ENTITY_MODELS as $type => $modelClass) {
            $rows = $this->enabledScope($type, $modelClass::query())->get();
            $total = 0;
            $have = 0;
            foreach ($rows as $row) {
                if ($type === 'monsters') {
                    foreach (self::GRADES as $grade) {
                        $total++;
                        if ($this->gradeStatus($row, $grade, $latest) === 'approved') {
                            $have++;
                        }
                    }
                } else {
                    $total++;
                    if ($this->singleStatus($type, $row, $latest) === 'approved') {
                        $have++;
                    }
                }
            }
            $coverage[] = ['type' => $type, 'have' => $have, 'total' => $total];
        }

        return response()->json(['coverage' => $coverage]);
    }

    public function queue(Request $request)
    {
        $status = $request->query('status', 'pending');
        $query = ArtAsset::with(['submitter', 'reviewer'])->orderByDesc('id');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        return response()->json(['queue' => $query->get()->map(fn (ArtAsset $a) => $this->submissionRow($a))]);
    }

    public function approve(Request $request, int $id)
    {
        abort_unless($request->user()->isGm(), 403, 'GM access required.');

        $asset = ArtAsset::findOrFail($id);
        abort_unless($asset->status === 'pending', 422, 'Only pending submissions can be approved.');
        abort_unless(Storage::disk('local')->exists($asset->upload_path), 404, 'Upload no longer available.');

        $modelClass = self::ENTITY_MODELS[$asset->entity_type];
        $row = $modelClass::findOrFail($asset->entity_id);

        // A monster's sprite can be aliased to a different basename than its own key (e.g. two monsters
        // sharing one piece of art — see MonsterSeeder) — reuse that existing base name rather than
        // $row->key so a later grade for the same monster lands next to the earlier ones instead of
        // splitting across two different basenames.
        $baseName = $row->sprite ?: $row->key;
        $relative = "{$asset->entity_type}/{$baseName}".($asset->grade ? "_{$asset->grade}" : '').'.png';
        $destination = public_path("images/{$relative}");
        if (! is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0755, true);
        }
        copy(Storage::disk('local')->path($asset->upload_path), $destination);

        if (! $row->sprite) {
            $row->sprite = $row->key;
            $row->save();
        }

        $asset->update([
            'status' => 'approved',
            'reviewed_by' => $request->user()->id,
            'review_notes' => $request->input('notes') ?: null,
            'reviewed_at' => now(),
        ]);

        AuditLog::record($request->user()->id, 'gm.art.approve', $asset->entity_type, $row->id, ['grade' => $asset->grade, 'art_asset_id' => $asset->id]);

        return response()->json(['submission' => $this->submissionRow($asset->fresh())]);
    }

    public function reject(Request $request, int $id)
    {
        abort_unless($request->user()->isGm(), 403, 'GM access required.');

        $data = $request->validate(['notes' => ['required', 'string', 'max:1000']]);
        $asset = ArtAsset::findOrFail($id);
        abort_unless($asset->status === 'pending', 422, 'Only pending submissions can be rejected.');

        $asset->update([
            'status' => 'rejected',
            'reviewed_by' => $request->user()->id,
            'review_notes' => $data['notes'],
            'reviewed_at' => now(),
        ]);

        AuditLog::record($request->user()->id, 'gm.art.reject', $asset->entity_type, $asset->entity_id, ['grade' => $asset->grade, 'art_asset_id' => $asset->id]);

        return response()->json(['submission' => $this->submissionRow($asset->fresh())]);
    }

    /** Latest ArtAsset per (entity_type, entity_id, grade) slot — resubmissions create new rows rather
     * than updating old ones, so history survives a rejection, but every status lookup only cares about
     * the newest attempt for a given slot. */
    private function latestAssetsBySlot(): array
    {
        $out = [];
        foreach (ArtAsset::orderBy('id')->get() as $asset) {
            $out["{$asset->entity_type}:{$asset->entity_id}:{$asset->grade}"] = $asset;
        }

        return $out;
    }

    /** Disabled rows (kept in the DB rather than deleted, e.g. field_mouse — see MonsterSeeder) are never
     * shown to players, so they shouldn't show up asking for art either — without this, a disabled
     * monster and whatever superseded it under the same display name both surface, reading as a
     * duplicate. Skills has no `enabled` column at all, so it's a no-op there. */
    private function enabledScope(string $type, $query)
    {
        return $type === 'skills' ? $query : $query->where('enabled', true);
    }

    private function gradeStatus(Monster $monster, string $grade, array $latest): string
    {
        if ($monster->sprite && file_exists(public_path("images/monsters/{$monster->sprite}_{$grade}.png"))) {
            return 'approved';
        }

        return $latest["monsters:{$monster->id}:{$grade}"]->status ?? 'open';
    }

    private function singleStatus(string $type, $row, array $latest): string
    {
        if ($row->sprite && file_exists(public_path("images/{$type}/{$row->sprite}.png"))) {
            return 'approved';
        }

        return $latest["{$type}:{$row->id}:"]->status ?? 'open';
    }

    private function slotRow(string $type, $row, ?array $single, ?array $grades, bool $done): array
    {
        return [
            'type' => $type,
            'entity_id' => $row->id,
            'key' => $row->key,
            'name' => $row->name,
            'glyph' => $row->glyph,
            'sprite' => $row->sprite,
            'level' => $row->min_level ?? $row->level_req ?? null,
            'is_boss' => (bool) ($row->is_boss ?? false),
            // "Place" grouping (see ArtStudioPage's groupBy) needs an honest bucket per type — lumping
            // every item/skill/dungeon (which have no zone at all) into the same "No Zone" label as an
            // actual zoneless monster reads like 609 monsters are missing their zone, when really it's
            // ~605 entities that were never zone-scoped to begin with plus a handful of tutorial monsters
            // (deliberately zone_id=null, see MonsterSeeder) mixed in.
            'zone_key' => match (true) {
                $type === 'monsters' => $row->zone?->key ?? 'tutorial',
                $type === 'zones' => $row->key,
                default => 'unscoped',
            },
            'zone_label' => match (true) {
                $type === 'monsters' => $row->zone?->name ?? 'Tutorial (no zone)',
                $type === 'zones' => $row->name,
                default => 'Not zone-specific',
            },
            // The zone's own min_level (not this entity's) — lets the frontend sort "Place" groups low
            // to high by actual zone order instead of alphabetically. Null for tutorial/unscoped, which
            // the frontend sorts to the front/back respectively rather than treating as level 0.
            'zone_min_level' => match (true) {
                $type === 'monsters' => $row->zone?->min_level,
                $type === 'zones' => $row->min_level,
                default => null,
            },
            'tier_kind' => match ($type) {
                'items' => 'rarity',
                'zones' => 'danger',
                'dungeons' => 'difficulty',
                'skills' => 'skill_tier',
                default => null,
            },
            'tier_value' => match ($type) {
                'items' => $row->rarity,
                'zones' => $row->danger,
                'dungeons' => $row->difficulty,
                'skills' => $row->tier,
                default => null,
            },
            'single' => $single,
            'grades' => $grades,
            'done' => $done,
        ];
    }

    /** Reads back dimensions/size/alpha-transparency from a just-stored upload — genuinely computed
     * (not guessed) so the Art Review detail panel's "automatic spec check" list reflects the real file.
     * Padding/subject-centering isn't checked here (would need a real pixel bounding-box scan) — these
     * four are what's cheaply and honestly verifiable from getimagesize()/GD alone. */
    private function readImageMeta(string $absolutePath): array
    {
        $info = @getimagesize($absolutePath);
        $hasAlpha = false;
        if ($info && function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring(file_get_contents($absolutePath));
            if ($img) {
                imagealphablending($img, false);
                imagesavealpha($img, true);
                $corner = imagecolorat($img, 0, 0);
                $hasAlpha = (($corner & 0x7F000000) >> 24) > 0;
                imagedestroy($img);
            }
        }

        return [
            'width' => $info[0] ?? null,
            'height' => $info[1] ?? null,
            'file_size_kb' => (int) round(filesize($absolutePath) / 1024),
            'has_alpha' => $hasAlpha,
        ];
    }

    private function submissionRow(ArtAsset $asset): array
    {
        $modelClass = self::ENTITY_MODELS[$asset->entity_type] ?? null;
        $eagerLoad = $asset->entity_type === 'monsters' ? ['zone'] : [];
        $entity = $modelClass ? $modelClass::with($eagerLoad)->find($asset->entity_id) : null;
        $gradeSuffix = $asset->grade ? "_{$asset->grade}" : '';

        $baseName = $entity?->sprite ?: $entity?->key;
        $currentLive = ($entity && $entity->sprite) ? asset("images/{$asset->entity_type}/{$entity->sprite}{$gradeSuffix}.png") : null;
        $targetPath = $baseName ? "public/images/{$asset->entity_type}/{$baseName}{$gradeSuffix}.png" : null;

        return [
            'id' => $asset->id,
            'entity_type' => $asset->entity_type,
            'entity_id' => $asset->entity_id,
            'entity_label' => $asset->entity_label,
            'grade' => $asset->grade,
            'status' => $asset->status,
            'notes' => $asset->notes,
            'review_notes' => $asset->review_notes,
            'submitted_by' => $asset->submitter?->name,
            'submitted_at' => $asset->created_at?->diffForHumans(),
            'reviewed_by' => $asset->reviewer?->name,
            'reviewed_at' => $asset->reviewed_at?->diffForHumans(),
            'current_live' => $currentLive,
            'target_path' => $targetPath,
            'zone_label' => $entity && $asset->entity_type === 'monsters' ? ($entity->zone?->name ?? 'No zone') : null,
            'is_boss' => (bool) ($entity->is_boss ?? false),
            'checks' => [
                ['label' => 'Image format', 'ok' => true, 'value' => strtoupper(pathinfo($asset->upload_path, PATHINFO_EXTENSION))],
                ['label' => 'Has transparency', 'ok' => $asset->has_alpha, 'value' => $asset->has_alpha ? 'Yes' : 'No'],
                ['label' => 'Square dimensions', 'ok' => $asset->width && $asset->width === $asset->height, 'value' => $asset->width ? "{$asset->width}×{$asset->height}" : 'unknown'],
                ['label' => 'Under '.self::MAX_UPLOAD_KB.'KB', 'ok' => $asset->file_size_kb <= self::MAX_UPLOAD_KB, 'value' => "{$asset->file_size_kb} KB"],
            ],
        ];
    }
}
