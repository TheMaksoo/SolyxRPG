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
        Schema::create('market_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_character_id')->constrained('characters')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->unsignedInteger('qty')->default(1);
            $table->unsignedInteger('durability')->nullable();
            $table->unsignedInteger('durability_max')->nullable();
            $table->unsignedBigInteger('price_gold');
            $table->string('status')->default('active'); // active|sold|cancelled|expired
            $table->foreignId('buyer_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('sold_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('market_listings');
    }
};
