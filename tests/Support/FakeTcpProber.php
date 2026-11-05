<?php

namespace Tests\Support;

use App\Contracts\TcpProber;
use App\Support\TcpResult;

/**
 * A TcpProber that answers from a script instead of the network.
 *
 * Probing is the one part of this application that genuinely talks to the
 * outside world, so the tests replace it wholesale: the suite must not depend
 * on the machine's DNS, firewall or internet connection.
 */
class FakeTcpProber implements TcpProber
{
    /** @var array<string, TcpResult> */
    protected array $results = [];

    protected TcpResult $default;

    /** @var array<int, string> */
    public array $calls = [];

    /** @var array<string, bool> */
    protected array $resolvable = [];

    protected bool $resolvesByDefault = true;

    public function __construct()
    {
        $this->default = TcpResult::connected(12);
    }

    public function connect(string $host, int $port, float $timeout, bool $tls = false): TcpResult
    {
        $this->calls[] = $host.':'.$port;

        return $this->results[$host.':'.$port]
            ?? $this->results[$host]
            ?? $this->default;
    }

    public function resolves(string $host): bool
    {
        return $this->resolvable[$host] ?? $this->resolvesByDefault;
    }

    /**
     * @param  string  $target  A host, or "host:port" to script one port only.
     */
    public function answer(string $target, TcpResult $result): static
    {
        $this->results[$target] = $result;

        return $this;
    }

    public function answerEverything(TcpResult $result): static
    {
        $this->default = $result;

        return $this;
    }

    public function cannotResolve(string $host): static
    {
        $this->resolvable[$host] = false;

        return $this;
    }

    /**
     * How many connections were attempted, used to prove that the per-cycle
     * cache really does spare a shared host from being probed repeatedly.
     */
    public function callCount(?string $target = null): int
    {
        if ($target === null) {
            return count($this->calls);
        }

        return count(array_filter(
            $this->calls,
            fn (string $call) => $call === $target || str_starts_with($call, $target.':')
        ));
    }
}
