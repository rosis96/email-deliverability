<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exact daily sending capacity for a batch-day: the SUM of each sending
 * mailbox's own Instantly daily limit. Previously capacity was always
 * active_accounts x one averaged limit, which under-counts batches that mix
 * limits (e.g. 3/day and 8/day) and raised false "over-sending" problems.
 * Null = unknown, so manual/CSV rows keep using active_accounts x limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_activity', function (Blueprint $table) {
            $table->integer('daily_capacity')->nullable()->after('daily_limit_per_account');
        });
    }

    public function down(): void
    {
        Schema::table('daily_activity', function (Blueprint $table) {
            $table->dropColumn('daily_capacity');
        });
    }
};
