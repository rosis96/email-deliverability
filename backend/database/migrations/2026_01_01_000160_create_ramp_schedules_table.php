<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ramp_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_id')->constrained('batches')->cascadeOnDelete();
            $table->integer('week_no');
            $table->integer('daily_limit_per_account');
            $table->decimal('target_utilisation', 5, 2)->nullable();
            $table->boolean('approved')->default(false);
            $table->date('review_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'week_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ramp_schedules');
    }
};
