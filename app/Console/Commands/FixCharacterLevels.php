<?php

namespace App\Console\Commands;

use App\Models\Character;
use Illuminate\Console\Command;

class FixCharacterLevels extends Command
{
    protected $signature = 'characters:fix-levels';

    protected $description = 'One-time fix: convert XP to cumulative and recalculate all character levels';

    public function handle(): int
    {
        $this->info('Converting XP to cumulative and fixing character levels...');

        $characters = Character::all();
        $fixed = 0;
        $unchanged = 0;

        foreach ($characters as $character) {
            $originalLevel = $character->level;
            $originalXp = $character->xp;
            $originalAttrPoints = $character->attribute_points;
            $originalSkillPoints = $character->skill_points;

            // Convert XP from "progress in level" to cumulative
            $cumulativeXp = $originalXp;
            if ($originalLevel > 1) {
                $cumulativeXp += Character::xpForLevel($originalLevel - 1);
            }

            // Calculate correct level from cumulative XP
            $correctLevel = 1;
            while ($correctLevel < Character::MAX_LEVEL && $cumulativeXp >= Character::xpForLevel($correctLevel)) {
                $correctLevel++;
            }

            $levelDiff = $correctLevel - $originalLevel;
            $attrPointsChange = $levelDiff * 3;
            $skillPointsChange = $levelDiff;

            $character->update([
                'xp' => $cumulativeXp,
                'level' => $correctLevel,
                'attribute_points' => $originalAttrPoints + $attrPointsChange,
                'skill_points' => $originalSkillPoints + $skillPointsChange,
            ]);

            $fixed++;
            if ($levelDiff !== 0 || $cumulativeXp !== $originalXp) {
                $action = $levelDiff > 0 ? 'Fixed (leveled up)' : ($levelDiff < 0 ? 'Fixed (leveled down)' : 'Fixed (XP converted)');
                $this->line(sprintf(
                    '%s %s (ID: %d): Level %d → %d, XP %d → %d (cumulative), %+d attr pts, %+d skill pts',
                    $action,
                    $character->name,
                    $character->id,
                    $originalLevel,
                    $correctLevel,
                    $originalXp,
                    $cumulativeXp,
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
