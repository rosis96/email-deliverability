<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Individual mailbox exceptions, always linked to their batch.
        Schema::create('account_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_id')->constrained('batches')->cascadeOnDelete();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignUuid('email_account_id')->nullable()->constrained('email_accounts')->nullOnDelete();
            // smtp_auth | imap_disconnect | password | cancelled_order | restriction | throttling | other
            $table->string('type');
            $table->string('severity')->default('medium');
            $table->text('symptom');
            $table->text('sample_dialog')->nullable();    // sanitised; no credentials
            $table->string('status')->default('open');     // open | investigating | resolved
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_exceptions');
    }
};
