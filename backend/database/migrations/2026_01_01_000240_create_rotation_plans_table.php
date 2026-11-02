<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rotation_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('current_batch_id')->constrained('batches')->restrictOnDelete();
            $table->foreignUuid('replacement_batch_id')->nullable()->constrained('batches')->nullOnDelete();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            // identified | replacement_created | infra_verified | warmup | placement_validated
            // | approved | overlapping | transitioning | retired
            $table->string('status')->default('identified');
            $table->date('overlap_start')->nullable();
            $table->date('overlap_end')->nullable();
            $table->date('retirement_date')->nullable();
            $table->string('transition_status')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rotation_plans');
    }
};
