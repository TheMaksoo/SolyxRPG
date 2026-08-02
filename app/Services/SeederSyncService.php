<?php

namespace App\Services;

use App\Models\Item;
use App\Models\Monster;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * SeederSyncService — keeps seeder files in sync with GM content changes.
 *
 * When a GM edits an item/monster/etc. via the content editor, we also update the
 * corresponding seeder file so running `db:seed` later won't revert the change.
 */
class SeederSyncService
{
    /**
     * Map of resource types to their seeder file paths and model classes.
     */
    private const SEEDER_MAP = [
        'items' => [
            'path' => 'database/seeders/ItemSeeder.php',
            'model' => Item::class,
            'array_name' => '$items',
        ],
        'monsters' => [
            'path' => 'database/seeders/MonsterSeeder.php',
            'model' => Monster::class,
            'array_name' => '$monsters',
        ],
    ];

    /**
     * Update a seeder file when a resource is modified via GM console.
     *
     * @param string $resource Resource type (e.g., 'items', 'monsters')
     * @param object $model The updated model instance
     * @return bool Whether the seeder was successfully updated
     */
    public function syncToSeeder(string $resource, $model): bool
    {
        // Only sync supported resources
        if (!isset(self::SEEDER_MAP[$resource])) {
            return false;
        }

        $config = self::SEEDER_MAP[$resource];
        $seederPath = base_path($config['path']);

        // Check if seeder file exists
        if (!File::exists($seederPath)) {
            Log::warning("Seeder file not found: {$seederPath}");
            return false;
        }

        try {
            $content = File::get($seederPath);
            $updatedContent = $this->updateSeederContent($content, $model, $resource);

            if ($updatedContent !== $content) {
                File::put($seederPath, $updatedContent);
                Log::info("Updated seeder file for {$resource}", [
                    'resource' => $resource,
                    'key' => $model->key ?? $model->id,
                ]);
                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::error("Failed to update seeder file", [
                'resource' => $resource,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Update the seeder content with new model data.
     *
     * This parses the seeder file, finds the matching entry by 'key', and updates it.
     */
    private function updateSeederContent(string $content, $model, string $resource): string
    {
        $key = $model->key;

        // Find the line with this key using regex
        // Pattern matches: ['key' => 'some_key', ... everything until the closing ],
        $pattern = "/\['key'\s*=>\s*'" . preg_quote($key, '/') . "'[^\]]*\],/s";

        if (!preg_match($pattern, $content, $matches)) {
            Log::warning("Could not find entry in seeder for key: {$key}");
            return $content;
        }

        // Generate the new array entry from the model
        $newEntry = $this->generateArrayEntry($model, $resource);

        // Replace the old entry with the new one
        $updatedContent = preg_replace($pattern, $newEntry . ',', $content);

        return $updatedContent;
    }

    /**
     * Generate a PHP array representation of the model for the seeder.
     */
    private function generateArrayEntry($model, string $resource): string
    {
        $data = $model->toArray();

        // Remove fields that shouldn't be in the seeder
        unset($data['id'], $data['created_at'], $data['updated_at']);

        // Format the array as PHP code
        $lines = ["['key' => '{$model->key}'"];

        foreach ($data as $field => $value) {
            if ($field === 'key') {
                continue; // Already added as first item
            }

            $formattedValue = $this->formatValue($value);
            $lines[] = "'{$field}' => {$formattedValue}";
        }

        // Join with proper indentation
        $indent = '            ';
        return $indent . '[' . implode(', ', $lines) . ']';
    }

    /**
     * Format a value for PHP code representation.
     */
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

        // String - escape single quotes
        $escaped = str_replace("'", "\\'", $value);
        return "'{$escaped}'";
    }
}
