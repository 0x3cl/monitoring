<?php

namespace App\Contracts;

use App\Support\TcpResult;

/**
 * Opens a TCP connection to decide whether a host is answering.
 *
 * An interface rather than a concrete call so the probing services can be
 * tested without opening real sockets, which would make the suite slow and
 * dependent on the machine's network.
 */
interface TcpProber
{
    /**
     * @param  bool  $tls  Negotiate TLS, so a certificate expiry can be read
     *                     from the same connection the probe already needs.
     */
    public function connect(string $host, int $port, float $timeout, bool $tls = false): TcpResult;

    /**
     * Can this hostname be resolved at all?
     *
     * Checked before connecting so an unresolvable name is reported as a DNS
     * failure rather than a connection timeout.
     */
    public function resolves(string $host): bool;
}
