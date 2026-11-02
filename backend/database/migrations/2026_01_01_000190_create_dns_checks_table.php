<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_checks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('domain_id')->nullable()->constrained('domains')->nullOnDelete();
            $table->foreignUuid('batch_id')->nullable()->constrained('batches')->nullOnDelete();
            $table->foreignUuid('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            // spf | dkim | dmarc | mx | blacklist | propagation | alignment
            $table->string('check_type');
            $table->string('result');          // pass | fail | warning | unknown
            $table->text('evidence')->nullable();
            $table->string('source')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->timestamps();

            $table->index(['domain_id', 'check_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_checks');
    }
};
