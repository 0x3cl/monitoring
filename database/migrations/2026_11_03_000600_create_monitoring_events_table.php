<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type');
            $table->string('severity');
            // When the event happened inside the monitored application.
            $table->timestampTz('occurred_at');
            // When SENTINEL accepted it.
            $table->timestampTz('received_at');
            $table->string('correlation_id', 64)->nullable();
            // Stable signature used to group recurring errors into incidents.
            $table->string('fingerprint', 64)->nullable();
            $table->text('message')->nullable();

            // Denormalized hot columns: indexed directly instead of reaching
            // into the JSON payload on every dashboard query.
            $table->string('http_method', 10)->nullable();
            $table->string('endpoint')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->string('exception_class')->nullable();

            $table->jsonb('data')->nullable();

            $table->index(['application_id', 'occurred_at']);
            $table->index(['application_id', 'severity', 'occurred_at']);
            $table->index(['application_id', 'event_type', 'occurred_at']);
            $table->index(['application_id', 'fingerprint']);
            $table->index(['endpoint', 'occurred_at']);
            $table->index('correlation_id');
            $table->index('occurred_at');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX monitoring_events_data_idx
                 ON monitoring_events USING GIN (data jsonb_path_ops)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_events');
    }
};
