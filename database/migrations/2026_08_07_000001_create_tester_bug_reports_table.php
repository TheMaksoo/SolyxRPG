<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tester-submitted bug reports tied to a specific changelog entry.
        // GMs can "acknowledge" a report which promotes it to a KnownBug entry.
        Schema::create('tester_bug_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('changelog_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description');
            // null = open, 'acknowledged' = GM promoted to KnownBug
            $table->string('status')->default('open');
            $table->foreignId('known_bug_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // Tester vote (accept) on a changelog entry — one row per user/entry pair.
        Schema::create('tester_update_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('changelog_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['changelog_id', 'user_id']);
        });

        // Track when a GM pushed a changelog entry live.
        Schema::table('changelogs', function (Blueprint $table) {
            $table->timestamp('pushed_live_at')->nullable()->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('changelogs', function (Blueprint $table) {
            $table->dropColumn('pushed_live_at');
        });
        Schema::dropIfExists('tester_update_votes');
        Schema::dropIfExists('tester_bug_reports');
    }
};
