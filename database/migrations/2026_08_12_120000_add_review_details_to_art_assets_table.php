<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** `notes` was being overwritten on approve/reject with the GM's note, destroying the artist's
     * original submission note — split into two columns so both survive independently. The width/height/
     * file_size_kb/has_alpha columns back the Art Review detail panel's "automatic spec check" list,
     * computed once at submit() time (see GmArtController) rather than re-reading the file on every
     * queue fetch. */
    public function up(): void
    {
        Schema::table('art_assets', function (Blueprint $table) {
            $table->text('review_notes')->nullable()->after('notes');
            $table->unsignedInteger('width')->nullable()->after('review_notes');
            $table->unsignedInteger('height')->nullable()->after('width');
            $table->unsignedInteger('file_size_kb')->default(0)->after('height');
            $table->boolean('has_alpha')->default(false)->after('file_size_kb');
        });
    }

    public function down(): void
    {
        Schema::table('art_assets', function (Blueprint $table) {
            $table->dropColumn(['review_notes', 'width', 'height', 'file_size_kb', 'has_alpha']);
        });
    }
};
