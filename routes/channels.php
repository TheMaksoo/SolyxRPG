<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('world-chat', function () {
    return true; // Public channel
});

Broadcast::channel('user.{userId}.badges', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
