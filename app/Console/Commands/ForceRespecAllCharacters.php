<?php

namespace App\Console\Commands;

use App\Models\Character;
use App\Services\RespecService;
use Illuminate\Console\Command;

class ForceRespecAllCharacters extends Command
{
    protected $signature = 'characters:force-respec-all';

    protected $description = 'GM-only bulk correction: resets every character\'s skills, branch picks, and attributes (refunding all points) at no gem cost. Use after a skill-tree content change breaks previously-unlocked builds — not a player-facing feature.';

    public function handle(RespecService $respec): int
    {
        $characterCount = 0;
        $skillPointsRefunded = 0;
        $attributePointsRefunded = 0;

        Character::with(['skills', 'attributes_'])->chunk(100, function ($characters) use ($respec, &$characterCount, &$skillPointsRefunded, &$attributePointsRefunded) {
            foreach ($characters as $character) {
                $result = $respec->respec($character);
                $characterCount++;
                $skillPointsRefunded += $result['skill_refund'];
                $attributePointsRefunded += $result['attribute_refund'];
            }
        });

        $this->info("Force-respec'd {$characterCount} character(s). Refunded {$skillPointsRefunded} skill point(s) and {$attributePointsRefunded} attribute point(s) total. No gems were charged.");

        return self::SUCCESS;
    }
}
