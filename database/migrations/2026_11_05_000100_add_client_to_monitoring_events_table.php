<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_events', function (Blueprint $table) {
            // The agent already sends these, but until now they sat in the JSON
            // payload where no index could reach them. Traffic analysis counts
            // distinct clients per day, so they have to be real columns.
            $table->string('client_ip', 45)->nullable()->after('exception_class');
            $table->string('user_agent')->nullable()->after('client_ip');

            // Serves "requests and distinct clients for this application on
            // this day", which is the only shape the aggregator queries in.
            $table->index(['application_id', 'occurred_at', 'client_ip'], 'monitoring_events_traffic_idx');
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_events', function (Blueprint $table) {
            $table->dropIndex('monitoring_events_traffic_idx');
            $table->dropColumn(['client_ip', 'user_agent']);
        });
    }
};
