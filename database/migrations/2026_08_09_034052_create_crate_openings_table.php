<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A crate that's mid-unlock: rewards are rolled and stored at open() time (not re-rolled at
     * collect), so a later GM balance change never retroactively affects a crate already opened. */
    public function up(): void
    {
        Schema::create('crate_openings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained();
            $table->string('size');
            $table->json('rewards_json');
            $table->timestamp('started_at');
            $table->timestamp('completes_at');
            $table->timestamp('collected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crate_openings');
    }
};
