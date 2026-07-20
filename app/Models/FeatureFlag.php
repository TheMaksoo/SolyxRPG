<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeatureFlag extends Model
{
    protected $fillable = ['key', 'name', 'enabled', 'tester_only'];
    protected $casts = ['enabled' => 'boolean', 'tester_only' => 'boolean'];

    /** LIVE (enabled) makes a feature reachable by everyone, full stop. Otherwise it's only reachable
     * if TESTERS is also on and this user is a tester — and if both switches are off, nobody gets in,
     * not even a GM/owner: this is meant as a hard kill-switch, not an admin-bypassable preview flag. */
    public function isAccessibleTo(User $user): bool
    {
        if ($this->enabled) {
            return true;
        }

        return $this->tester_only && $user->isTester();
    }

    /** Looks up a flag by key and checks access, defaulting to accessible when the flag hasn't been
     * seeded yet (an unseeded install shouldn't accidentally lock everyone out of everything). */
    public static function gate(string $key, User $user): bool
    {
        $flag = static::where('key', $key)->first();

        return ! $flag || $flag->isAccessibleTo($user);
    }
}
