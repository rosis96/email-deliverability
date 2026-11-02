<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every external/destructive action is gated through here. The app never
        // executes against external platforms automatically.
        Schema::create('approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // pause_campaign | reconnect_account | delete_account | change_limit
            // | modify_warmup | replace_domain | purchase_plan | other
            $table->string('action_type');
            $table->text('proposed_action');
            $table->text('evidence')->nullable();
            $table->foreignUuid('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->foreignUuid('batch_id')->nullable()->constrained('batches')->nullOnDelete();
            $table->integer('accounts_affected')->nullable();
            $table->text('risk_description')->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // pending | approved | rejected | executed | cancelled
            $table->string('status')->default('pending');
            $table->text('execution_log')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'action_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};
