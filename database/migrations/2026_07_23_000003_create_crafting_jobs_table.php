<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A queued craft: materials are consumed and the result rolled at start, but only lands in inventory once collected after completes_at. */
    public function up(): void
    {
        Schema::create('crafting_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained();
            $table->foreignId('result_item_id')->constrained('items');
            $table->string('rarity');
            $table->integer('roll_pct')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completes_at');
            $table->timestamp('collected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crafting_jobs');
    }
};
