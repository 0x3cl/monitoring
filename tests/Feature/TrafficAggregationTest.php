<?php

namespace Tests\Feature;

use App\Jobs\AggregateTraffic;
use App\Models\Application;
use App\Models\DailyTrafficStat;
use App\Models\MonitoringEvent;
use App\Services\Traffic\TrafficAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Rolling request events up into one row per day.
 */
class TrafficAggregationTest extends TestCase
{
    use RefreshDatabase;

    protected Application $application;

    protected function setUp(): void
    {
        parent::setUp();

        $this->application = Application::factory()->create();
    }

    public function test_it_counts_requests_into_status_buckets(): void
    {
        $this->request(['http_status' => 200]);
        $this->request(['http_status' => 204]);
        // A redirect is a request the application handled, so it counts as
        // served rather than as an error.
        $this->request(['http_status' => 302]);
        $this->request(['http_status' => 404]);
        $this->request(['http_status' => 401]);
        $this->request(['http_status' => 500]);

        $stat = $this->aggregate();

        $this->assertSame(6, $stat->requests);
        $this->assertSame(3, $stat->success);
        $this->assertSame(2, $stat->client_errors);
        $this->assertSame(1, $stat->server_errors);
        $this->assertSame(50.0, $stat->errorRate());
    }

    public function test_it_counts_distinct_clients_rather_than_requests(): void
    {
        $this->request(['client_ip' => '203.0.113.1']);
        $this->request(['client_ip' => '203.0.113.1']);
        $this->request(['client_ip' => '203.0.113.2']);
        $this->request(['client_ip' => null]);

        $this->assertSame(2, $this->aggregate()->unique_visitors);
    }

    public function test_it_records_the_response_time_tail_not_only_the_average(): void
    {
        foreach ([100, 100, 100, 100, 100, 100, 100, 100, 100, 5000] as $ms) {
            $this->request(['response_time_ms' => $ms]);
        }

        $stat = $this->aggregate();

        // The average is dragged by one outlier; p95 and max are what show it.
        $this->assertSame(590, $stat->avg_response_time_ms);
        $this->assertSame(5000, $stat->p95_response_time_ms);
        $this->assertSame(5000, $stat->max_response_time_ms);
    }

    public function test_it_ignores_events_that_are_not_served_requests(): void
    {
        $this->request(['http_status' => 200]);

        // An exception and one of SENTINEL's own heartbeat transitions are not
        // requests the application served.
        MonitoringEvent::factory()->for($this->application)->create([
            'event_type' => 'exception',
            'occurred_at' => now(),
            'received_at' => now(),
        ]);
        MonitoringEvent::factory()->for($this->application)->create([
            'event_type' => 'application_status',
            'occurred_at' => now(),
            'received_at' => now(),
        ]);

        $this->assertSame(1, $this->aggregate()->requests);
    }

    public function test_recomputing_a_day_corrects_it_rather_than_double_counting(): void
    {
        $this->request(['http_status' => 200]);
        $this->aggregate();

        // What a late batch from an agent draining its offline spool looks like.
        $this->request(['http_status' => 200]);
        $stat = $this->aggregate();

        $this->assertSame(2, $stat->requests);
        $this->assertSame(1, DailyTrafficStat::query()->count());
    }

    public function test_a_day_with_no_traffic_writes_no_row(): void
    {
        $this->assertNull($this->aggregate());
        $this->assertSame(0, DailyTrafficStat::query()->count());
    }

    public function test_it_ranks_the_busiest_endpoints_and_clients(): void
    {
        foreach (range(1, 5) as $ignored) {
            $this->request(['endpoint' => '/api/orders', 'client_ip' => '203.0.113.1', 'http_status' => 200]);
        }

        $this->request(['endpoint' => '/login', 'client_ip' => '198.51.100.7', 'http_status' => 401]);

        $stat = $this->aggregate();

        $this->assertSame('/api/orders', $stat->top_endpoints[0]['endpoint']);
        $this->assertSame(5, $stat->top_endpoints[0]['requests']);
        $this->assertSame('203.0.113.1', $stat->top_clients[0]['client_ip']);
        // Errors are carried alongside the volume, so a caller producing
        // nothing but 401s stands out.
        $this->assertSame(1, collect($stat->top_clients)->firstWhere('client_ip', '198.51.100.7')['errors']);
    }

    public function test_top_lists_are_capped_so_a_daily_row_stays_bounded(): void
    {
        config()->set('sentinel.traffic.top_n', 3);

        foreach (range(1, 8) as $index) {
            $this->request(['endpoint' => "/page/{$index}", 'http_status' => 200]);
        }

        $this->assertCount(3, $this->aggregate()->top_endpoints);
    }

    public function test_it_records_the_sample_rate_so_counts_can_be_labelled(): void
    {
        $this->request(['http_status' => 200, 'data' => ['sample_rate' => 0.25]]);
        $this->request(['http_status' => 200, 'data' => ['sample_rate' => 0.1]]);

        // The lowest rate seen: the most conservative reading of the day.
        $this->assertSame(0.1, $this->aggregate()->sample_rate);
    }

    public function test_full_capture_is_recorded_as_a_census(): void
    {
        $this->request(['http_status' => 200]);

        $this->assertSame(1.0, $this->aggregate()->sample_rate);
    }

    public function test_only_the_requested_day_is_counted(): void
    {
        $this->request(['occurred_at' => now()->subDays(3)]);
        $this->request(['occurred_at' => now()]);

        $this->assertSame(1, $this->aggregate()->requests);
        $this->assertSame(1, $this->aggregate(Carbon::today()->subDays(3))->requests);
    }

    public function test_the_command_queues_one_job_per_application(): void
    {
        Queue::fake();

        Application::factory()->count(2)->create();
        Application::factory()->disabled()->create();

        $this->artisan('sentinel:aggregate-traffic')->assertSuccessful();

        // Three active applications including the one from setUp; the disabled
        // one is skipped.
        Queue::assertPushed(AggregateTraffic::class, 3);
        Queue::assertPushed(
            AggregateTraffic::class,
            fn ($job) => $job->queue === config('sentinel.traffic.queue')
        );
    }

    public function test_aggregation_can_be_switched_off(): void
    {
        Queue::fake();
        config()->set('sentinel.traffic.enabled', false);

        $this->artisan('sentinel:aggregate-traffic')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_rows_past_the_retention_window_are_pruned(): void
    {
        config()->set('sentinel.retention.traffic_day_days', 30);

        $kept = DailyTrafficStat::factory()->forDay(now()->subDays(10)->toDateString())->create();
        $expired = DailyTrafficStat::factory()->forDay(now()->subDays(400)->toDateString())->create();

        $this->artisan('sentinel:aggregate-traffic')->assertSuccessful();

        $this->assertModelExists($kept);
        $this->assertModelMissing($expired);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function request(array $attributes = []): MonitoringEvent
    {
        return MonitoringEvent::factory()->for($this->application)->create([
            'event_type' => ($attributes['response_time_ms'] ?? 0) >= 3000 ? 'slow_request' : 'http_request',
            'occurred_at' => now(),
            'received_at' => now(),
            'endpoint' => '/',
            'http_status' => 200,
            'response_time_ms' => 100,
            'client_ip' => '203.0.113.1',
            ...$attributes,
        ]);
    }

    protected function aggregate(?Carbon $day = null): ?DailyTrafficStat
    {
        return app(TrafficAggregator::class)->aggregate($this->application, $day ?? Carbon::today());
    }
}
