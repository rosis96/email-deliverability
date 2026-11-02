<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('code')->unique();                     // NE, UNI, REV...
            $table->string('external_instantly_id')->nullable();  // only if verified
            // active | expired | unverified
            $table->string('status')->default('active');
            // connected | disconnected | not_configured | permission_denied | sync_failed
            $table->string('integration_status')->default('not_configured');
            $table->boolean('counts_toward_totals')->default(true); // expired/unverified => false
            $table->timestamp('last_synced_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
