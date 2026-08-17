<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PhpToken;

/**
 * SeederSyncService — keeps seeder files in sync with GM content changes.
 *
 * When a GM creates/edits/deletes a row via the content editor, we also patch the corresponding
 * seeder file on disk so a future `db:seed` run reproduces the GM's change instead of reverting it.
 *
 * Seeders come in two shapes:
 *  - 'list': a `$var = [ [...], [...] ]` array literal looped through `Model::updateOrCreate(['key' => ...], $row)`.
 *  - 'calls': a handful of individual `Model::updateOrCreate([...], [...])` statements, no wrapping array
 *    (only `events` today).
 *
 * Entries are located with PHP's own tokenizer (`PhpToken::tokenize()`) rather than line regex, so a
 * bracket inside a string/comment can't throw off depth tracking and multi-line entries work correctly.
 * Resources whose seeder fields don't correspond 1:1 to DB columns (e.g. `recipes`, which stores item
 * *keys* resolved through a seed-time `$ids[...]` lookup rather than the literal `result_item_id`/
 * `materials_json` ids the DB stores) are deliberately excluded — see SEEDER_MAP below.
 */
class SeederSyncService
{
    private const SEEDER_MAP = [
        'items' => ['path' => 'database/seeders/ItemSeeder.php', 'shape' => 'list', 'array_name' => 'items', 'match_field' => 'key'],
        'monsters' => ['path' => 'database/seeders/MonsterSeeder.php', 'shape' => 'list', 'array_name' => 'monsters', 'match_field' => 'key'],
        'zones' => ['path' => 'database/seeders/ZoneSeeder.php', 'shape' => 'list', 'array_name' => 'zones', 'match_field' => 'key'],
        'dungeons' => ['path' => 'database/seeders/DungeonSeeder.php', 'shape' => 'list', 'array_name' => 'dungeons', 'match_field' => 'key'],
        'quests' => ['path' => 'database/seeders/QuestSeeder.php', 'shape' => 'list', 'array_name' => 'quests', 'match_field' => 'key'],
        'skills' => ['path' => 'database/seeders/SkillSeeder.php', 'shape' => 'list', 'array_name' => 'skills', 'match_field' => 'key'],
        'pets' => ['path' => 'database/seeders/PetSeeder.php', 'shape' => 'list', 'array_name' => 'pets', 'match_field' => 'key'],
        'cosmetics' => ['path' => 'database/seeders/CosmeticSeeder.php', 'shape' => 'list', 'array_name' => 'cosmetics', 'match_field' => 'key'],
        'events' => ['path' => 'database/seeders/EventSeeder.php', 'shape' => 'calls', 'model_short' => 'Event', 'match_field' => 'name'],
    ];

    /** Fields never written into a seeder entry — DB-generated/internal. */
    private const STRIP_FIELDS = ['id', 'created_at', 'updated_at'];

    public function isEligible(string $resource): bool
    {
        return isset(self::SEEDER_MAP[$resource]);
    }

    public function seederPath(string $resource): ?string
    {
        return isset(self::SEEDER_MAP[$resource]) ? base_path(self::SEEDER_MAP[$resource]['path']) : null;
    }

    public function create(string $resource, $model): bool
    {
        return $this->mutate($resource, $model, 'create');
    }

    public function update(string $resource, $model): bool
    {
        return $this->mutate($resource, $model, 'update');
    }

    public function delete(string $resource, $model): bool
    {
        return $this->mutate($resource, $model, 'delete');
    }

    private function mutate(string $resource, $model, string $op): bool
    {
        if (!isset(self::SEEDER_MAP[$resource])) {
            return false;
        }

        $config = self::SEEDER_MAP[$resource];
        $path = base_path($config['path']);

        if (!File::exists($path)) {
            Log::warning("Seeder file not found for {$resource}: {$path}");
            return false;
        }

        $matchField = $config['match_field'];
        $value = (string) $model->{$matchField};

        try {
            $content = File::get($path);
            $entries = $config['shape'] === 'calls'
                ? $this->locateCallEntries($content, $config['model_short'])
                : $this->locateListEntries($content, $config['array_name']);

            if ($entries === null) {
                Log::warning("Could not locate seeder structure for {$resource} in {$path}");
                return false;
            }

            $match = $this->findEntry($content, $entries['items'], $matchField, $value);

            $updated = match ($op) {
                'update' => $match
                    ? $this->replaceEntry($content, $match, $this->generateEntry($model, $config, $matchField, $value))
                    : null,
                'create' => $match
                    ? null // already exists — nothing to create
                    : $this->insertEntry($content, $entries, $this->generateEntry($model, $config, $matchField, $value), $config['shape']),
                'delete' => $match
                    ? $this->removeEntry($content, $match)
                    : null,
            };

            if ($updated === null || $updated === $content) {
                if ($op !== 'create' || $match) {
                    Log::warning("Seeder sync ({$op}) found no actionable entry for {$resource}:{$value}");
                }
                return false;
            }

            File::put($path, $updated);
            Log::info("Seeder sync ({$op}) applied for {$resource}:{$value}");

            return true;
        } catch (\Throwable $e) {
            Log::error('Seeder sync failed', ['resource' => $resource, 'op' => $op, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Locate the `$arrayName = [ ... ]` list and every top-level element inside it.
     *
     * @return array{open:int,close:int,items:array<int,array{start:int,end:int,commaPos:?int}>}|null
     */
    private function locateListEntries(string $content, string $arrayName): ?array
    {
        $tokens = PhpToken::tokenize($content);
        $count = count($tokens);

        $openIdx = null;
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->id === T_VARIABLE && $tokens[$i]->text === '$' . $arrayName) {
                // Skip whitespace/comments to the '=', then to the opening '['.
                $j = $this->nextSubstantive($tokens, $i + 1);
                if ($j === null || $tokens[$j]->text !== '=') {
                    continue;
                }
                $k = $this->nextSubstantive($tokens, $j + 1);
                if ($k !== null && $tokens[$k]->text === '[') {
                    $openIdx = $k;
                    break;
                }
            }
        }

        if ($openIdx === null) {
            return null;
        }

        $items = [];
        $depth = 1;
        $elementStart = null;
        $closeIdx = null;

        for ($i = $openIdx + 1; $i < $count; $i++) {
            $t = $tokens[$i];
            $isOpener = in_array($t->text, ['[', '(', '{'], true);
            $isCloser = in_array($t->text, [']', ')', '}'], true);

            if ($isCloser) {
                $depth--;
                if ($depth === 0) {
                    if ($elementStart !== null) {
                        $items[] = ['start' => $elementStart, 'end' => $t->pos, 'commaPos' => null];
                    }
                    $closeIdx = $i;
                    break;
                }
                continue;
            }

            if ($isOpener) {
                if ($elementStart === null) {
                    $elementStart = $t->pos;
                }
                $depth++;
                continue;
            }

            if ($depth === 1 && $t->text === ',') {
                if ($elementStart !== null) {
                    $items[] = ['start' => $elementStart, 'end' => $t->pos, 'commaPos' => $t->pos];
                }
                $elementStart = null;
                continue;
            }

            if ($t->id === T_WHITESPACE || $t->id === T_COMMENT || $t->id === T_DOC_COMMENT) {
                continue;
            }

            if ($elementStart === null) {
                $elementStart = $t->pos;
            }
        }

        if ($closeIdx === null) {
            return null;
        }

        return ['open' => $tokens[$openIdx]->pos, 'close' => $tokens[$closeIdx]->pos, 'items' => $items];
    }

    /**
     * Locate every `{ModelShort}::updateOrCreate(...);` statement in the file.
     *
     * @return array{open:null,close:int,items:array<int,array{start:int,end:int,commaPos:null}>}|null
     */
    private function locateCallEntries(string $content, string $modelShort): ?array
    {
        $tokens = PhpToken::tokenize($content);
        $count = count($tokens);

        $items = [];
        $lastEnd = null;

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->id !== T_STRING || $tokens[$i]->text !== $modelShort) {
                continue;
            }

            $j = $this->nextSubstantive($tokens, $i + 1);
            if ($j === null || $tokens[$j]->id !== T_DOUBLE_COLON) {
                continue;
            }
            $k = $this->nextSubstantive($tokens, $j + 1);
            if ($k === null || $tokens[$k]->id !== T_STRING || $tokens[$k]->text !== 'updateOrCreate') {
                continue;
            }
            $l = $this->nextSubstantive($tokens, $k + 1);
            if ($l === null || $tokens[$l]->text !== '(') {
                continue;
            }

            $depth = 1;
            $closeIdx = null;
            for ($m = $l + 1; $m < $count; $m++) {
                if ($tokens[$m]->text === '(') {
                    $depth++;
                } elseif ($tokens[$m]->text === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $closeIdx = $m;
                        break;
                    }
                }
            }

            if ($closeIdx === null) {
                continue;
            }

            $semiIdx = $this->nextSubstantive($tokens, $closeIdx + 1);
            $end = ($semiIdx !== null && $tokens[$semiIdx]->text === ';') ? $tokens[$semiIdx]->pos + 1 : $tokens[$closeIdx]->pos + 1;

            $items[] = ['start' => $tokens[$i]->pos, 'end' => $end, 'commaPos' => null];
            $lastEnd = $end;
            $i = $semiIdx ?? $closeIdx;
        }

        return ['open' => null, 'close' => $lastEnd, 'items' => $items];
    }

    private function nextSubstantive(array $tokens, int $from): ?int
    {
        $count = count($tokens);
        for ($i = $from; $i < $count; $i++) {
            if ($tokens[$i]->id !== T_WHITESPACE && $tokens[$i]->id !== T_COMMENT && $tokens[$i]->id !== T_DOC_COMMENT) {
                return $i;
            }
        }
        return null;
    }

    private function findEntry(string $content, array $items, string $matchField, string $value): ?array
    {
        $pattern = "/'" . preg_quote($matchField, '/') . "'\\s*=>\\s*'" . preg_quote($value, '/') . "'/";

        foreach ($items as $item) {
            $slice = substr($content, $item['start'], $item['end'] - $item['start']);
            if (preg_match($pattern, $slice)) {
                return $item;
            }
        }

        return null;
    }

    private function replaceEntry(string $content, array $match, string $newEntryText): string
    {
        return substr($content, 0, $match['start']) . $newEntryText . substr($content, $match['end']);
    }

    private function removeEntry(string $content, array $match): string
    {
        // Trim this entry's own leading indentation back to the newline that starts its line, so we
        // don't leave a dangling blank/whitespace-only line behind (a standalone comment above the
        // entry stops the walk-back and is deliberately left in place).
        $start = $match['start'];
        while ($start > 0 && in_array($content[$start - 1], [' ', "\t"], true)) {
            $start--;
        }
        // If that leaves a blank line before us (entries separated by a blank line, e.g. the `calls`
        // shape), swallow one of the two newlines so removal doesn't leave a gap behind.
        if ($start >= 2 && $content[$start - 1] === "\n" && $content[$start - 2] === "\n") {
            $start--;
        }

        $end = $match['end'];
        if ($match['commaPos'] !== null) {
            $end = $match['commaPos'] + 1; // swallow the trailing comma
        }
        // Also swallow a single trailing newline so the file doesn't accumulate blank lines.
        if (substr($content, $end, 1) === "\r") {
            $end++;
        }
        if (substr($content, $end, 1) === "\n") {
            $end++;
        }

        return substr($content, 0, $start) . substr($content, $end);
    }

    private function insertEntry(string $content, array $entries, string $newEntryText, string $shape): string
    {
        if ($shape === 'calls') {
            $insertAt = $entries['items'] ? $entries['close'] : null;
            if ($insertAt === null) {
                return $content; // no anchor to insert after — bail out rather than guess
            }

            return substr($content, 0, $insertAt) . "\n\n        " . $newEntryText . substr($content, $insertAt);
        }

        // list shape: insert right before the outer closing bracket, after the last element
        // (adding a trailing comma to the previous last element if it doesn't already have one).
        $items = $entries['items'];
        $insertAt = $entries['close'];

        if ($items) {
            $last = $items[array_key_last($items)];
            if ($last['commaPos'] === null) {
                $content = substr($content, 0, $last['end']) . ',' . substr($content, $last['end']);
                $insertAt = $entries['close'] + 1;
            }
        }

        return substr($content, 0, $insertAt) . "            " . $newEntryText . ",\n        " . substr($content, $insertAt);
    }

    private function generateEntry($model, array $config, string $matchField, string $value): string
    {
        $data = $model->toArray();
        foreach (self::STRIP_FIELDS as $f) {
            unset($data[$f]);
        }
        unset($data[$matchField]);

        if ($config['shape'] === 'calls') {
            $lines = [];
            foreach ($data as $field => $fieldValue) {
                $lines[] = "            '{$field}' => " . $this->formatValue($fieldValue) . ',';
            }

            return "{$config['model_short']}::updateOrCreate(['{$matchField}' => '" . $this->escape($value) . "'], [\n"
                . implode("\n", $lines) . "\n        ]);";
        }

        $parts = ["'{$matchField}' => '" . $this->escape($value) . "'"];
        foreach ($data as $field => $fieldValue) {
            $parts[] = "'{$field}' => " . $this->formatValue($fieldValue);
        }

        return '[' . implode(', ', $parts) . ']';
    }

    private function formatValue($value): string
    {
        if (is_null($value)) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_numeric($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return "'" . $this->escape((string) $value) . "'";
    }

    private function escape(string $value): string
    {
        return str_replace("'", "\\'", $value);
    }
}
