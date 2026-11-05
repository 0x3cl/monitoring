<?php

namespace Tests\Feature;

use App\Jobs\AggregateTraffic;
use App\Models\Application;
use App\Models\DailyTrafficStat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * What the traffic dashboard is served.
 */
class TrafficApiTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_it_returns_one_entry_per_day_including_quiet_days(): void
    {
        $application = Application::factory()->create();

        DailyTrafficStat::factory()->for($application)->forDay(now()->toDateString())->create(['requests' => 100]);
        DailyTrafficStat::factory()->for($application)->forDay(now()->subDays(3)->toDateString())->create(['requests' => 50]);

        $response = $this->actingAs($this->admin())->getJson('/api/admin/traffic?days=7')->assertOk();

        // Seven days whatever was recorded: a missing day has to read as a
        // quiet day, not squeeze the week into two columns.
        $response->assertJsonCount(7, 'data.series');
        $this->assertSame(0, $response->json('data.series.0.requests'));
        $this->assertSame(100, $response->json('data.series.6.requests'));
    }

    public function test_it_summarizes_volume_errors_and_speed(): void
    {
        $application = Application::factory()->create();

        DailyTrafficStat::factory()->for($application)->create([
            'requests' => 1000,
            'success' => 900,
            'client_errors' => 60,
            'server_errors' => 40,
            'unique_visitors' => 210,
            'avg_response_time_ms' => 120,
        ]);

        $this->actingAs($this->admin())
            ->getJson('/api/admin/traffic?days=7')
            ->assertOk()
            ->assertJsonPath('data.totals.requests', 1000)
            // Client errors count towards the rate: a flood of 404s is a real
            // signal, not noise.
            ->assertJsonPath('data.totals.error_rate', 10.0)
            ->assertJsonPath('data.totals.peak_visitors', 210)
            ->assertJsonPath('data.totals.avg_response_time_ms', 120);
    }

    public function test_visitors_are_reported_as_the_busiest_day_never_a_sum(): void
    {
        $application = Application::factory()->create();

        DailyTrafficStat::factory()->for($application)->forDay(now()->toDateString())->create(['unique_visitors' => 90]);
        DailyTrafficStat::factory()->for($application)->forDay(now()->subDay()->toDateString())->create(['unique_visitors' => 140]);

        // Summing would double count everyone who came back two days running.
        $this->actingAs($this->admin())
            ->getJson('/api/admin/traffic?days=7')
            ->assertOk()
            ->assertJsonPath('data.totals.peak_visitors', 140);
    }

    public function test_it_can_be_scoped_to_one_application(): void
    {
        $wanted = Application::factory()->create();
        $other = Application::factory()->create();

        DailyTrafficStat::factory()->for($wanted)->create(['requests' => 10]);
        DailyTrafficStat::factory()->for($other)->create(['requests' => 999]);

        $this->actingAs($this->admin())
            ->getJson("/api/admin/traffic?days=7&application={$wanted->app_code}")
            ->assertOk()
            ->assertJsonPath('data.totals.requests', 10)
            ->assertJsonPath('data.application', $wanted->app_code)
            // The per-application breakdown is the combined page's own section.
            ->assertJsonPath('data.applications', []);
    }

    public function test_the_combined_report_breaks_traffic_down_by_application(): void
    {
        $busy = Application::factory()->create(['name' => 'Orders API']);
        $quiet = Application::factory()->create(['name' => 'Admin Portal']);

        DailyTrafficStat::factory()->for($busy)->create(['requests' => 900]);
        DailyTrafficStat::factory()->for($quiet)->create(['requests' => 20]);

        $this->actingAs($this->admin())
            ->getJson('/api/admin/traffic?days=7')
            ->assertOk()
            ->assertJsonPath('data.applications.0.app_code', $busy->app_code)
            ->assertJsonPath('data.applications.1.app_code', $quiet->app_code);
    }

    public function test_an_unoffered_range_falls_back_rather_than_scanning_everything(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/admin/traffic?days=4000')
            ->assertOk()
            ->assertJsonPath('data.range.days', 7);
    }

    public function test_it_reports_when_the_counts_are_only_a_sample(): void
    {
        $application = Application::factory()->create();

        DailyTrafficStat::factory()->for($application)->sampled(0.1)->create();

        $this->actingAs($this->admin())
            ->getJson('/api/admin/traffic?days=7')
            ->assertOk()
            ->assertJsonPath('data.sample_rate', 0.1);
    }

    public function test_rebuilding_the_rollups_is_queued_not_run_inline(): void
    {
        Queue::fake();

        $application = Application::factory()->create();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/applications/{$application->app_code}/traffic/refresh", ['days' => 30])
            ->assertStatus(202);

        Queue::assertPushed(
            AggregateTraffic::class,
            fn ($job) => $job->applicationId === $application->id && $job->days === 30
        );
    }

    public function test_traffic_requires_an_administrator(): void
    {
        $this->getJson('/api/admin/traffic')->assertUnauthorized();

        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->getJson('/api/admin/traffic')
            ->assertForbidden();
    }
}
