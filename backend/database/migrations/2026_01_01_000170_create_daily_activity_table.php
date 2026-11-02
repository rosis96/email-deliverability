<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One source record per batch per day. All rates are derived, not stored.
        Schema::create('daily_activity', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_id')->constrained('batches')->cascadeOnDelete();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->date('activity_date');

            $table->integer('active_accounts')->nullable();
            $table->integer('connected_accounts')->nullable();
            $table->integer('sent')->default(0);
            $table->integer('daily_limit_per_account')->nullable();
            $table->integer('replies')->default(0);
            $table->integer('positive_replies')->default(0);
            $table->integer('bounces')->default(0);
            $table->integer('hard_bounces')->default(0);
            $table->integer('soft_bounces')->default(0);
            $table->integer('rejections')->default(0);
            $table->integer('warmup_emails')->nullable();
            $table->string('campaign_status')->nullable();
            $table->string('source')->default('manual');   // manual | csv | instantly
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'activity_date']);
            $table->index('activity_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_activity');
    }
};
