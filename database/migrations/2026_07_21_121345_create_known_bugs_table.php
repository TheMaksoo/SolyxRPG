<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('known_bugs', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description');
            $table->string('area')->nullable(); // e.g. "Battle", "Crafting" — lets players scan by page
            $table->string('status')->default('reported'); // reported|investigating|fixed
            $table->string('severity')->default('minor'); // minor|major|critical
            $table->timestamp('fixed_at')->nullable();
            $table->timestamps();

            $table->index(['status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('known_bugs');
    }
};
