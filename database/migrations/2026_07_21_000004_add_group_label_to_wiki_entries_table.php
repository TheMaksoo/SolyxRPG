<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wiki_entries', function (Blueprint $table) {
            // A finer sub-category within `category` — zone name for monsters, class name for items —
            // so the Wiki can offer a "filter within this tab" row instead of one long flat list.
            // Null means "no sub-grouping" (dungeons/skills/classes/events/pets).
            $table->string('group_label')->nullable()->after('category');
            $table->index(['category', 'group_label']);
        });
    }

    public function down(): void
    {
        Schema::table('wiki_entries', function (Blueprint $table) {
            $table->dropIndex(['category', 'group_label']);
            $table->dropColumn('group_label');
        });
    }
};
