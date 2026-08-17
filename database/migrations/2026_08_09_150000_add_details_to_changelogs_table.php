<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('changelogs', function (Blueprint $table) {
            // Optional expandable "full breakdown" shown behind a Show details toggle on the
            // Changelog page. Nullable — the auto-changelog PR workflow never populates this.
            $table->longText('details')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('changelogs', function (Blueprint $table) {
            $table->dropColumn('details');
        });
    }
};
