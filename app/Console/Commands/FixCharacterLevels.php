<?php

namespace App\Console\Commands;

use App\Models\Character;
use Illuminate\Console\Command;

class FixCharacterLevels extends Command
{
    protected $signature = 'characters:fix-levels';

    protected $description = 'One-time fix: recalculate all character levels based on their current XP';

    public function handle(): int
    {
        $this->info('Fixing character levels based on XP...');

        $characters = Character::all();
        $fixed = 0;
        $unchanged = 0;

        foreach ($characters as $character) {
            $originalLevel = $character->level;
            $originalXp = $character->xp;
            $originalAttrPoints = $character->attribute_points;
            $originalSkillPoints = $character->skill_points;

            // Calculate correct level based on cumulative XP
            $correctLevel = 1;
            while ($correctLevel < Character::MAX_LEVEL && $character->xp >= Character::xpForLevel($correctLevel)) {
                $correctLevel++;
            }

            if ($correctLevel !== $originalLevel) {
                $levelDiff = $correctLevel - $originalLevel;
                $attrPointsChange = $levelDiff * 3;
                $skillPointsChange = $levelDiff;

                $character->update([
                    'level' => $correctLevel,
                    'attribute_points' => $originalAttrPoints + $attrPointsChange,
                    'skill_points' => $originalSkillPoints + $skillPointsChange,
                ]);

                $fixed++;
                $action = $levelDiff > 0 ? 'Fixed (leveled up)' : 'Fixed (leveled down)';
                $this->line(sprintf(
                    '%s %s (ID: %d): Level %d → %d (XP: %d, %+d attr pts, %+d skill pts)',
                    $action,
                    $character->name,
                    $character->id,
                    $originalLevel,
                    $correctLevel,
                    $originalXp,
                    $attrPointsChange,
                    $skillPointsChange
                ));
            } else {
                $unchanged++;
            }
        }

        $this->info("Done! Fixed: {$fixed}, Unchanged: {$unchanged}");

        return 0;
    }
}
