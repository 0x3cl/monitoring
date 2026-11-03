<?php

namespace DostPtri\SentinelAgent\Transport;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Throwable;

/**
 * Delivers events to the SENTINEL ingestion API.
 *
 * Monitoring must never disrupt the application it monitors, so every failure
 * path here is swallowed: a transport error buffers the event and returns
 * false rather than throwing into the host application's request cycle.
 */
class HttpTransport
{
    public function __construct(
        protected HttpFactory $http,
        protected ?EventBuffer $buffer,
        protected string $endpoint,
        protected string $key,
        protected string $header = 'X-Sentinel-Key',
        protected float $timeout = 2.0,
        protected int $retries = 2,
        protected int $retryDelayMs = 200,
    ) {}

    /**
     * @param  array<string, mixed>  $event
     */
    public function send(array $event): bool
    {
        try {
            $response = $this->request()->post($this->endpoint, $event);

            if ($response->successful()) {
                return true;
            }

            // A rejected event is a payload problem: retrying it would fail
            // forever, so only transport-level failures are buffered.
            if ($response->status() >= 400 && $response->status() < 500) {
                return false;
            }
        } catch (Throwable) {
            // Fall through to buffering.
        }

        $this->buffer?->push($event);

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    public function sendBatch(array $events): bool
    {
        if ($events === []) {
            return true;
        }

        try {
            $response = $this->request()->post(
                $this->batchEndpoint(),
                ['events' => array_values($events)]
            );

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }

    public function ping(): bool
    {
        try {
            return $this->request()->get($this->pingEndpoint())->successful();
        } catch (Throwable) {
            return false;
        }
    }

    protected function request(): PendingRequest
    {
        return $this->http
            ->withHeaders([
                $this->header => $this->key,
                'Accept' => 'application/json',
            ])
            ->timeout($this->timeout)
            ->retry($this->retries, $this->retryDelayMs, throw: false);
    }

    protected function batchEndpoint(): string
    {
        return rtrim($this->endpoint, '/').'/batch';
    }

    protected function pingEndpoint(): string
    {
        return preg_replace('#/events$#', '/ping', rtrim($this->endpoint, '/')) ?? $this->endpoint;
    }
}
