<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reputation_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_id')->nullable()->constrained('batches')->nullOnDelete();
            $table->foreignUuid('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->foreignUuid('domain_id')->nullable()->constrained('domains')->nullOnDelete();
            $table->string('source');            // postmaster | snds | internal
            $table->date('captured_at');
            $table->decimal('hard_bounce_rate', 5, 2)->nullable();
            $table->decimal('soft_bounce_rate', 5, 2)->nullable();
            $table->decimal('complaint_rate', 5, 2)->nullable();
            $table->decimal('spam_rate', 5, 2)->nullable();
            // Only set when the source actually provides it. Never inferred.
            $table->string('reputation_label')->nullable();  // high | medium | low | bad
            $table->text('evidence')->nullable();
            $table->timestamps();

            $table->index(['source', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reputation_snapshots');
    }
};
