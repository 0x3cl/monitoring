<?php

namespace DostPtri\SentinelAgent\Console;

use DostPtri\SentinelAgent\Transport\EventBuffer;
use DostPtri\SentinelAgent\Transport\HttpTransport;
use Illuminate\Console\Command;

/**
 * Delivers events that were buffered while SENTINEL was unreachable.
 *
 * Schedule this every minute so a brief outage costs nothing.
 */
class SentinelFlushCommand extends Command
{
    protected $signature = 'sentinel:flush {--limit= : Maximum events to send}';

    protected $description = 'Send monitoring events buffered during a SENTINEL outage';

    public function handle(EventBuffer $buffer, HttpTransport $transport): int
    {
        $limit = (int) ($this->option('limit') ?: config('sentinel.buffer.batch_size', 100));
        $pending = $buffer->count();

        if ($pending === 0) {
            $this->components->info('Nothing buffered.');

            return self::SUCCESS;
        }

        $events = $buffer->take($limit);

        if ($transport->sendBatch($events)) {
            $this->components->info(count($events).' buffered event(s) delivered.');

            return self::SUCCESS;
        }

        // Delivery failed: put them back rather than dropping them.
        foreach ($events as $event) {
            $buffer->push($event);
        }

        $this->components->warn('SENTINEL is still unreachable; events remain buffered.');

        return self::FAILURE;
    }
}
