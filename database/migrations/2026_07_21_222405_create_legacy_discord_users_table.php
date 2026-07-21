<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recovered user IDs from the original Discord-bot version of Solyx (a 2023-07-08 mongodump,
     * the Discord cluster itself no longer exists) — used purely as a one-time lookup so a current
     * player who links Discord and matches one of these old accounts gets the "Legend of Solyx" title
     * automatically (see SocialiteController). Not gameplay data, so no foreign keys to users/characters.
     */
    public function up(): void
    {
        Schema::create('legacy_discord_users', function (Blueprint $table) {
            $table->id();
            $table->string('discord_id')->unique();
            $table->string('name')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legacy_discord_users');
    }
};
