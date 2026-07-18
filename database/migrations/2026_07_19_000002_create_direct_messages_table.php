<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direct_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('characters')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('characters')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['sender_id', 'recipient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_messages');
    }
};
