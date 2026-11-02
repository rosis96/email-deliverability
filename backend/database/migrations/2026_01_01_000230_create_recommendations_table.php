<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_problem_id')->nullable()->constrained('batch_problems')->cascadeOnDelete();
            $table->foreignUuid('batch_id')->nullable()->constrained('batches')->nullOnDelete();
            $table->foreignUuid('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->string('rule_key')->nullable();
            $table->text('detected');                 // what was detected
            $table->text('why');                      // why it matters
            $table->text('evidence')->nullable();
            $table->text('suggested_investigation');
            $table->text('expected_impact')->nullable();
            $table->boolean('approval_required')->default(false);
            $table->boolean('is_ai_generated')->default(false);
            // suggested | accepted | dismissed | actioned
            $table->string('status')->default('suggested');
            $table->timestamps();

            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendations');
    }
};
