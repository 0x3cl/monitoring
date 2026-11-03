<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            // api | file | fluentbit
            $table->string('type')->default('api');
            $table->string('path')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();

            $table->index(['application_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_sources');
    }
};
