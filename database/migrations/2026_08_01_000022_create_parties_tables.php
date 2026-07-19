<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leader_character_id')->constrained('characters')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('party_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('joined_at')->useCurrent();
        });

        Schema::create('party_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inviter_character_id')->constrained('characters')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['party_id', 'character_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_invites');
        Schema::dropIfExists('party_members');
        Schema::dropIfExists('parties');
    }
};
