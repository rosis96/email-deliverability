<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Raw import provenance. Kept for reconciliation; never overwrites verified data.
        Schema::create('imported_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source');                 // instantly | maildoso | csv
            $table->string('external_record_id')->nullable();
            $table->string('entity_type')->nullable(); // account | campaign | activity | placement
            $table->foreignUuid('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->foreignUuid('batch_id')->nullable()->constrained('batches')->nullOnDelete();
            $table->string('original_period')->nullable();
            // high | medium | low | unmapped
            $table->string('batch_mapping_confidence')->default('unmapped');
            $table->json('payload');
            $table->timestamp('imported_at');
            $table->timestamps();

            $table->index(['source', 'entity_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imported_records');
    }
};
