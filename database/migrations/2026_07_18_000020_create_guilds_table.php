<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guilds', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('tag', 5);
            $table->unsignedInteger('level')->default(1);
            $table->unsignedInteger('xp_perk')->default(0);
            $table->string('war_status')->default('none');
            $table->foreignId('owner_id')->constrained('characters')->cascadeOnDelete();
            $table->unsignedInteger('member_cap')->default(30);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guilds');
    }
};
