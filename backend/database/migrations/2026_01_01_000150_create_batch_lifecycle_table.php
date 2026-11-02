<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Phase-transition history for a batch (append-only audit of lifecycle).
        Schema::create('batch_lifecycle', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_id')->constrained('batches')->cascadeOnDelete();
            $table->string('phase');            // setup | warmup | ramp | active | resting | retired
            $table->string('from_phase')->nullable();
            $table->timestamp('entered_at');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'entered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_lifecycle');
    }
};
