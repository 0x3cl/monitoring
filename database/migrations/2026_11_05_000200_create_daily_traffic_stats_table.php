<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per application per day.
     *
     * Monitoring events are pruned after 30 days, so without a rollup every
     * trend longer than a month would quietly vanish. These rows are tiny and
     * kept for a year, which is what makes "per day" a real history rather than
     * a window.
     *
     * It also keeps the dashboard off the events table: answering "requests per
     * day for the last 90 days" from raw events means aggregating millions of
     * rows on every page load.
     */
    public function up(): void
    {
        Schema::create('daily_traffic_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->date('day');

            $table->unsignedBigInteger('requests')->default(0);
            // Distinct client addresses seen that day. Counted from the events
            // still on hand, so it is exact while they are retained.
            $table->unsignedBigInteger('unique_visitors')->default(0);

            // Three buckets rather than five: an operator cares whether traffic
            // was served, rejected or broken. 3xx is folded into success
            // because a redirect is a request the application handled.
            $table->unsignedBigInteger('success')->default(0);
            $table->unsignedBigInteger('client_errors')->default(0);
            $table->unsignedBigInteger('server_errors')->default(0);
            $table->unsignedBigInteger('slow_requests')->default(0);

            $table->unsignedInteger('avg_response_time_ms')->nullable();
            // Carried because an average hides exactly the tail that matters.
            $table->unsignedInteger('p95_response_time_ms')->nullable();
            $table->unsignedInteger('max_response_time_ms')->nullable();

            // Top-N kept inline instead of in satellite tables: bounded in size,
            // read in the same query as the row, and nothing to join.
            $table->jsonb('top_endpoints')->nullable();
            $table->jsonb('top_clients')->nullable();

            // The lowest sample rate seen that day. Below 1.0 the counts are a
            // sample, not a census, and the dashboard has to say so rather than
            // present a confidently wrong number.
            $table->decimal('sample_rate', 4, 3)->default(1);

            $table->timestampsTz();

            $table->unique(['application_id', 'day']);
            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_traffic_stats');
    }
};
