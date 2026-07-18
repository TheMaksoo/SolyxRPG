<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guild_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guild_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['member', 'officer', 'master'])->default('member');
            $table->timestamps();

            $table->unique(['guild_id', 'character_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guild_members');
    }
};
