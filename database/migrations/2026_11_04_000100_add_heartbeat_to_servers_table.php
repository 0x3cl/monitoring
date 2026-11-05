<?php

use App\Enums\ReachabilityStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // What to connect to. Kept separate from hostname/ip_address so a
            // host behind a bastion can be probed at a different address than
            // the one it is documented under.
            $table->string('monitor_host')->nullable()->after('ip_address');
            $table->unsignedSmallInteger('monitor_port')->default(443)->after('monitor_host');
            $table->boolean('monitor_enabled')->default(true)->after('monitor_port');

            $table->string('reachability_status')
                ->default(ReachabilityStatus::Unknown->value)
                ->after('monitor_enabled');
            $table->timestampTz('last_probed_at')->nullable()->after('reachability_status');
            $table->timestampTz('last_reachable_at')->nullable()->after('last_probed_at');
            // A single refused connection is not an outage; a run of them is.
            $table->unsignedSmallInteger('consecutive_failures')->default(0)->after('last_reachable_at');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn([
                'monitor_host',
                'monitor_port',
                'monitor_enabled',
                'reachability_status',
                'last_probed_at',
                'last_reachable_at',
                'consecutive_failures',
            ]);
        });
    }
};
