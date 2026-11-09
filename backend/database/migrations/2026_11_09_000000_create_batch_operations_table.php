<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch-level writes to Instantly (tagging, stage actions, rotation) run in the
 * background after the HTTP response. Each run is tracked here so the UI can
 * show progress and the full outcome stays auditable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batch_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_id')->constrained('batches')->cascadeOnDelete();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            // tag_sync | assign | stage | rotate
            $table->string('type');
            $table->json('params')->nullable();
            // queued | running | completed | completed_with_errors | failed
            $table->string('status')->default('queued');
            $table->integer('total_steps')->default(0);
            $table->integer('done_steps')->default(0);
            $table->integer('failed_steps')->default(0);
            $table->json('log')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_operations');
    }
};
