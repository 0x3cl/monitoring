<?php

use App\Enums\UptimeStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->boolean('probe_enabled')->default(true)->after('health_endpoint');
            $table->unsignedInteger('probe_interval_seconds')->default(60)->after('probe_enabled');
            // Authenticates SENTINEL to the agent's health endpoint. Unlike the
            // ingestion credential, this one must be recoverable in plaintext to
            // be sent on every probe, so it is encrypted rather than hashed.
            $table->text('probe_token')->nullable()->after('probe_interval_seconds');

            $table->string('uptime_status')->default(UptimeStatus::Unknown->value)->after('probe_token');
            $table->string('uptime_diagnosis')->nullable()->after('uptime_status');
            $table->timestampTz('last_probe_at')->nullable()->after('uptime_diagnosis');
            $table->timestampTz('last_up_at')->nullable()->after('last_probe_at');
            $table->timestampTz('status_changed_at')->nullable()->after('last_up_at');
            // The last observation, so the detail view can show a status code
            // and a latency without a per-probe history table.
            $table->unsignedSmallInteger('last_probe_status_code')->nullable()->after('status_changed_at');
            $table->unsignedInteger('last_probe_time_ms')->nullable()->after('last_probe_status_code');
            $table->unsignedSmallInteger('consecutive_failures')->default(0)->after('last_probe_time_ms');
            $table->timestampTz('next_probe_at')->nullable()->after('consecutive_failures');

            // The dispatcher selects only applications that are actually due, so
            // its cost stays flat as the number of monitored applications grows.
            $table->index(['probe_enabled', 'next_probe_at']);
            $table->index('uptime_status');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropIndex(['probe_enabled', 'next_probe_at']);
            $table->dropIndex(['uptime_status']);

            $table->dropColumn([
                'probe_enabled',
                'probe_interval_seconds',
                'probe_token',
                'uptime_status',
                'uptime_diagnosis',
                'last_probe_at',
                'last_up_at',
                'status_changed_at',
                'last_probe_status_code',
                'last_probe_time_ms',
                'consecutive_failures',
                'next_probe_at',
            ]);
        });
    }
};
