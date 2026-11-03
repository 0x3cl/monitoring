<?php

namespace Tests\Feature;

use App\Enums\ConnectionStatus;
use App\Models\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectionStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_silent_applications_are_flagged_as_disconnected(): void
    {
        $silent = Application::factory()->connected()->create(['last_event_at' => now()->subHour()]);
        $reporting = Application::factory()->connected()->create(['last_event_at' => now()->subMinute()]);
        $neverSeen = Application::factory()->create();

        $this->artisan('sentinel:refresh-connection-status', ['--minutes' => 15])
            ->assertSuccessful();

        $this->assertSame(ConnectionStatus::Disconnected, $silent->fresh()->connection_status);
        $this->assertSame(ConnectionStatus::Connected, $reporting->fresh()->connection_status);
        // An application that has never reported stays pending, not disconnected.
        $this->assertSame(ConnectionStatus::NeverConnected, $neverSeen->fresh()->connection_status);
    }
}
