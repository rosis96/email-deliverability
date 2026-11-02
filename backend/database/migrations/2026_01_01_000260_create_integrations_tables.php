<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // instantly | maildoso | postmaster | snds | mxtoolbox | dns
            $table->string('provider');
            $table->foreignUuid('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            // connected | disconnected | not_configured | permission_denied | sync_failed
            $table->string('status')->default('not_configured');
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            // Non-secret config only. API keys live in env / encrypted store, never here.
            $table->json('config')->nullable();
            $table->timestamps();

            $table->index(['provider', 'status']);
        });

        Schema::create('integration_sync_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('integration_connection_id')->nullable()
                ->constrained('integration_connections')->cascadeOnDelete();
            $table->string('provider');
            $table->string('status');        // success | failed | partial
            $table->text('message')->nullable();
            $table->integer('records_processed')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_sync_logs');
        Schema::dropIfExists('integration_connections');
    }
};
