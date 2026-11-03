<?php

namespace DostPtri\SentinelAgent\Console;

use DostPtri\SentinelAgent\SentinelAgent;
use Illuminate\Console\Command;

/**
 * Confirms that this application can reach SENTINEL with its credential.
 */
class SentinelTestCommand extends Command
{
    protected $signature = 'sentinel:test {--event : Also submit a test event}';

    protected $description = 'Verify this application can reach SENTINEL';

    public function handle(SentinelAgent $agent): int
    {
        if (! $agent->enabled()) {
            $this->components->error(
                'SENTINEL is not configured. Set SENTINEL_APPLICATION_ID, SENTINEL_ENDPOINT and SENTINEL_KEY.'
            );

            return self::FAILURE;
        }

        $this->components->info('Contacting '.config('sentinel.endpoint').' …');

        if (! $agent->ping()) {
            $this->components->error('SENTINEL did not accept the connection. Check the endpoint and key.');

            return self::FAILURE;
        }

        $this->components->info('Connected as '.config('sentinel.application_id').'.');

        if ($this->option('event')) {
            $agent->custom('Connection test from '.config('app.name'))
                ? $this->components->info('Test event accepted.')
                : $this->components->warn('Test event was not accepted.');
        }

        return self::SUCCESS;
    }
}
