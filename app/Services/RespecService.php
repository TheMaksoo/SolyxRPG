<?php

namespace App\Services;

use App\Models\Character;

/** The actual "reset a character's build" mechanics, shared by the player-facing paid respec
 * (CharacterController::respecSkills(), which charges gems around a call to respec() below) and the
 * GM-only bulk correction (ForceRespecAllCharacters console command, which skips the charge entirely).
 * Keeping this in one place means both paths can never drift on what "a respec" actually resets. */
class RespecService
{
    public function __construct(private AttributeService $attributeService) {}

    /** Refunds every skill point ever spent, resets the 5-tier branch-pick ladder, and refunds/zeroes
     * every attribute point — returns the refunded amounts so the caller can grant them (and, for the
     * paid path, log the gem charge against them). Does not touch gems/GemLedger itself. */
    public function respec(Character $character): array
    {
        // Rank N of a skill costs N points, so the total ever spent reaching level L is 1+2+...+L —
        // except a free_grant skill (an arm's capstone, auto-granted at rank 1 for 0 points when you
        // commit to that arm — see CharacterController::unlockSkill()), whose rank-1 point was never
        // actually spent.
        $skillRefund = $character->skills->sum(function ($cs) {
            $spent = (int) ($cs->level * ($cs->level + 1) / 2);

            return $cs->free_grant ? $spent - 1 : $spent;
        });

        $attributes = $character->attributes_ ?? $character->attributes_()->create([]);
        $attributeRefund = $this->attributeService->totalSpentAll($attributes);
        $attributes->update([
            'damage' => 0, 'armor' => 0, 'hp_cap' => 0, 'hp_regen' => 0, 'mana_cap' => 0, 'mana_regen' => 0,
            'crit' => 0, 'crit_damage' => 0, 'luck' => 0, 'dodge' => 0, 'energy_cap' => 0, 'energy_regen' => 0,
            'mining_speed' => 0, 'chopping_speed' => 0, 'smelting_speed' => 0, 'crafting_speed' => 0, 'foraging_speed' => 0,
        ]);

        $character->skills()->delete();
        $character->update([
            'skill_points' => $character->skill_points + $skillRefund,
            'attribute_points' => $character->attribute_points + $attributeRefund,
            'spec_class' => null, 'profession' => null, 'ascension' => null, 'apex_path' => null, 'transcendence' => null,
            'active_deck_json' => null,
        ]);

        return ['skill_refund' => $skillRefund, 'attribute_refund' => $attributeRefund];
    }
}
