<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batch_problems', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('problem_code')->unique();        // PRB-0001
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignUuid('batch_id')->nullable()->constrained('batches')->nullOnDelete();
            $table->string('rule_key')->nullable();           // which detection rule fired
            $table->integer('accounts_affected')->nullable();
            $table->string('severity')->default('medium');    // low | medium | high | critical
            $table->text('symptom');
            $table->text('evidence')->nullable();
            // confirmed | suspected | unknown
            $table->string('root_cause_status')->default('unknown');
            $table->text('root_cause')->nullable();
            $table->text('impact')->nullable();
            $table->text('recommended_action')->nullable();
            $table->text('action_taken')->nullable();
            $table->boolean('approval_required')->default(false);
            $table->string('approval_owner')->nullable();
            $table->foreignId('assigned_operator_id')->nullable()->constrained('users')->nullOnDelete();
            // open | investigating | in_progress | waiting_approval | waiting_provider | resolved | closed
            $table->string('status')->default('open');
            $table->timestamp('detected_at');
            $table->date('retest_date')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'severity']);
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_problems');
    }
};
