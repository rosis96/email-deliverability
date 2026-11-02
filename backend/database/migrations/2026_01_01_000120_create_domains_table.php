<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignUuid('batch_id')->nullable()->constrained('batches')->nullOnDelete();
            $table->string('name');
            $table->string('provider')->nullable();

            // Authentication status: pass | fail | warning | unknown (null = not verified)
            $table->string('spf_status')->nullable();
            $table->string('dkim_status')->nullable();
            $table->string('dmarc_status')->nullable();
            $table->string('mx_status')->nullable();
            $table->string('blacklist_status')->nullable();

            $table->timestamp('last_verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
