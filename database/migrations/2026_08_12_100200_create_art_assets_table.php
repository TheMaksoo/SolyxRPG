<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** One row per art submission — NOT one row per "thing that needs art" (that list is just computed
     * live off each content table's own `sprite IS NULL`, see GmArtController::needed()). A row here only
     * exists once an artist has actually uploaded something for review. entity_type/entity_id point at
     * the source row (monsters/items/skills/zones/dungeons); entity_label is cached at submit time purely
     * for display, so the panel doesn't need a polymorphic join per entity_type to show a name. */
    public function up(): void
    {
        Schema::create('art_assets', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('entity_label');
            // Only meaningful for monsters (common/elite/champion/legendary) — see BattlePage.vue's
            // grade-aware sprite lookup. Null for every other entity type, which only ever get one image.
            $table->string('grade')->nullable();
            // Pending/rejected files live on the private 'local' disk (art-submissions/...), never the
            // public one — only approve() copies the bytes out to public/images/... where players can
            // actually fetch them, so an unreviewed upload is never accidentally player-visible.
            $table->string('upload_path');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('notes')->nullable();
            $table->foreignId('submitted_by')->constrained('users');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('art_assets');
    }
};
