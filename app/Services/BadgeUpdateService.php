<?php

namespace App\Services;

use App\Models\User;

class BadgeUpdateService
{
    /**
     * Marks a user's badges as updated so clients know to refetch.
     * Call this whenever an action changes badge counts (quest claimed, daily reward ready, etc.)
     */
    public function touch(User $user): void
    {
        $user->update(['badges_updated_at' => now()]);
    }

    /**
     * Touch badges for multiple users at once (e.g., party invite affects both sender and receiver).
     */
    public function touchMany(array $userIds): void
    {
        User::whereIn('id', $userIds)->update(['badges_updated_at' => now()]);
    }
}
