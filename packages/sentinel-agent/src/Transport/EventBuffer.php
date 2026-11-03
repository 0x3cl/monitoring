<?php

namespace DostPtri\SentinelAgent\Transport;

use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * A local spool for events that could not be delivered.
 *
 * A brief SENTINEL outage should not lose monitoring data, and it should not
 * grow without bound either: the spool is capped and the oldest entries are
 * dropped first.
 */
class EventBuffer
{
    public function __construct(
        protected Filesystem $disk,
        protected string $path,
        protected int $maxEvents = 1000,
    ) {}

    /**
     * @param  array<string, mixed>  $event
     */
    public function push(array $event): void
    {
        $line = json_encode($event);

        if ($line === false) {
            return;
        }

        $existing = $this->disk->exists($this->path) ? (string) $this->disk->get($this->path) : '';
        $lines = array_values(array_filter(explode(PHP_EOL, $existing), fn ($l) => trim($l) !== ''));
        $lines[] = $line;

        if (count($lines) > $this->maxEvents) {
            $lines = array_slice($lines, -$this->maxEvents);
        }

        $this->disk->put($this->path, implode(PHP_EOL, $lines).PHP_EOL);
    }

    /**
     * Remove and return up to $limit buffered events.
     *
     * @return array<int, array<string, mixed>>
     */
    public function take(int $limit): array
    {
        if (! $this->disk->exists($this->path)) {
            return [];
        }

        $lines = array_values(array_filter(
            explode(PHP_EOL, (string) $this->disk->get($this->path)),
            fn ($line) => trim($line) !== ''
        ));

        $taken = array_slice($lines, 0, $limit);
        $remaining = array_slice($lines, count($taken));

        $remaining === []
            ? $this->disk->delete($this->path)
            : $this->disk->put($this->path, implode(PHP_EOL, $remaining).PHP_EOL);

        return array_values(array_filter(array_map(
            fn (string $line) => json_decode($line, true),
            $taken
        ), 'is_array'));
    }

    public function count(): int
    {
        if (! $this->disk->exists($this->path)) {
            return 0;
        }

        return count(array_filter(
            explode(PHP_EOL, (string) $this->disk->get($this->path)),
            fn ($line) => trim($line) !== ''
        ));
    }
}
