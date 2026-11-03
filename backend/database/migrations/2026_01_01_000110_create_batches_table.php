<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Permanent human batch identifier, e.g. NE-SMTP-01. Never renamed.
            $table->string('batch_id')->unique();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            // smtp | smtp_maildoso | google_dfy_gen2 | other
            $table->string('provider_type');
            $table->string('domain_family')->nullable();

            // Source values only — all lifecycle maths are derived from these.
            $table->integer('planned_account_count')->nullable();
            $table->integer('actual_account_count')->default(0);
            $table->integer('active_account_count')->default(0);
            $table->integer('error_account_count')->default(0);

            $table->date('creation_date')->nullable();
            $table->date('warmup_start')->nullable();      // must be entered explicitly
            $table->integer('warmup_days')->default(21);
            $table->date('active_start')->nullable();
            $table->integer('lifecycle_days')->default(90);
            $table->integer('active_daily_limit')->nullable(); // per-account daily sending limit

            // Operator-controlled lifecycle phase (never auto-advanced past warmup).
            // setup | warmup | ramp | active | resting | retired
            $table->string('phase')->default('setup');
            $table->boolean('readiness_checks_passed')->default(false);

            $table->string('batch_owner')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->uuid('replacement_batch_id')->nullable();   // self reference (see below)
            // active | retired | archived
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();   // historical batches are never hard-deleted

            $table->index(['workspace_id', 'provider_type']);
            $table->index('phase');
        });

        // Self-referencing FK added AFTER the table exists. Postgres requires the
        // referenced key (batches.id PK) to be present before the constraint is
        // created; doing it inline in Schema::create fails on Postgres (SQLite,
        // used in tests, does not enforce this and so never surfaced it).
        Schema::table('batches', function (Blueprint $table) {
            $table->foreign('replacement_batch_id')->references('id')->on('batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};
