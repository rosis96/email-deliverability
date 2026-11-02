<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            // Every mailbox has a permanent batch association.
            $table->foreignUuid('batch_id')->constrained('batches')->restrictOnDelete();
            $table->foreignUuid('domain_id')->nullable()->constrained('domains')->nullOnDelete();

            $table->string('email');
            $table->string('external_id')->nullable();   // unique within source
            $table->string('source')->nullable();         // instantly | maildoso | manual
            $table->string('provider')->nullable();

            // active | error | disconnected | warming | reconnecting
            $table->string('status')->default('active');
            $table->string('connection_status')->nullable(); // connected | disconnected
            $table->boolean('warmup_enabled')->nullable();
            $table->string('imap_status')->nullable();
            $table->string('smtp_status')->nullable();

            // Sanitised error text ONLY. Never store passwords, SMTP creds or tokens.
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['source', 'external_id']);
            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_accounts');
    }
};
