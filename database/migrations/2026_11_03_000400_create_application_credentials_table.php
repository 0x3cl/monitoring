<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('name')->default('default');
            $table->string('token_prefix', 16);
            // sha256 hex of the plaintext token; the plaintext is never stored.
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('token_prefix');
            $table->index(['application_id', 'revoked_at']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX application_credentials_active_idx
                 ON application_credentials (token_hash) WHERE revoked_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('application_credentials');
    }
};
