<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk edits to many Instantly mailboxes at once (selected rows, a whole tag,
 * or everything matching a filter). Runs in the background; tracked here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->string('action');
            $table->json('params')->nullable();
            $table->json('emails')->nullable();
            // queued | running | completed | completed_with_errors | failed
            $table->string('status')->default('queued');
            $table->integer('total')->default(0);
            $table->integer('done')->default(0);
            $table->integer('failed')->default(0);
            $table->json('log')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_operations');
    }
};
