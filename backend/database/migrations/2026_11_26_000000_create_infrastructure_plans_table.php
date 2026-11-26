<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One editable infrastructure + meeting-forecast plan per workspace.
 * Stores INPUTS only (provider lines, targets, rates); every derived figure is
 * computed from them so it always reflects the current inputs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('infrastructure_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->unique()->constrained('workspaces')->cascadeOnDelete();
            $table->json('inputs');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infrastructure_plans');
    }
};
