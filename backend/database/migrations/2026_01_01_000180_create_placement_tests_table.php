<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('placement_tests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            // Null batch_id => workspace-level result. Never auto-copied to batches.
            $table->foreignUuid('batch_id')->nullable()->constrained('batches')->nullOnDelete();
            $table->string('scope')->default('workspace');    // workspace | batch
            $table->date('test_date');
            $table->text('tested_domains')->nullable();
            $table->string('provider')->nullable();           // google | microsoft | mixed
            $table->decimal('inbox_pct', 5, 2)->nullable();
            $table->decimal('spam_pct', 5, 2)->nullable();
            $table->decimal('missing_pct', 5, 2)->nullable();
            $table->integer('sample_size')->nullable();
            $table->string('source')->nullable();
            $table->text('evidence')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'test_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('placement_tests');
    }
};
