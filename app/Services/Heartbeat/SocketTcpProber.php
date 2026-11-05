<?php

namespace App\Services\Heartbeat;

use App\Contracts\TcpProber;
use App\Support\TcpResult;

/**
 * The real prober, using stream sockets.
 *
 * No ICMP: ping needs raw sockets (so, root), is blocked by most cloud
 * providers, and would mean shelling out from a queue worker. A TCP connection
 * to a port the host is known to serve answers the same question and is
 * universally available.
 */
class SocketTcpProber implements TcpProber
{
    public function connect(string $host, int $port, float $timeout, bool $tls = false): TcpResult
    {
        if (! $this->resolves($host)) {
            return TcpResult::failed('Host could not be resolved.');
        }

        // Peer verification is deliberately off: the probe needs to know
        // whether anything is listening and when the certificate expires, and
        // an invalid certificate is reported as a finding rather than being
        // allowed to mask a reachability answer.
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => $tls,
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $address = sprintf('%s://%s:%d', $tls ? 'ssl' : 'tcp', $host, $port);

        $startedAt = microtime(true);

        $socket = @stream_socket_client(
            $address,
            $errorCode,
            $errorMessage,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($socket === false) {
            return $this->classifyFailure((int) $errorCode, (string) $errorMessage, $latencyMs, $timeout);
        }

        $expiresAt = $tls ? $this->certificateExpiry($socket) : null;

        fclose($socket);

        return TcpResult::connected($latencyMs, $expiresAt);
    }

    public function resolves(string $host): bool
    {
        // An address needs no resolution; anything else must produce a record.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return gethostbyname($host) !== $host || @dns_get_record($host, DNS_A | DNS_AAAA) !== [];
    }

    /**
     * Separate a refusal from a timeout.
     *
     * A refusal proves the host is up and nothing is listening on that port,
     * which is the single most useful distinction the probe can draw.
     */
    protected function classifyFailure(int $code, string $message, int $latencyMs, float $timeout): TcpResult
    {
        // The error code is the system errno. The SOCKET_* constants come from
        // ext-sockets, which stream_socket_client itself does not require, so
        // the numeric values are used as a fallback.
        $refused = defined('SOCKET_ECONNREFUSED') ? SOCKET_ECONNREFUSED : 111;
        $timedOut = defined('SOCKET_ETIMEDOUT') ? SOCKET_ETIMEDOUT : 110;

        if ($code === $refused || str_contains(strtolower($message), 'refused')) {
            return TcpResult::refused($message);
        }

        $looksLikeTimeout = $code === $timedOut
            || str_contains(strtolower($message), 'timed out')
            // stream_socket_client reports a timeout as a generic failure after
            // roughly the full budget has elapsed.
            || $latencyMs >= (int) round($timeout * 1000 * 0.9);

        return $looksLikeTimeout
            ? TcpResult::timedOut($message)
            : TcpResult::failed($message);
    }

    /**
     * @param  resource  $socket
     */
    protected function certificateExpiry($socket): ?string
    {
        $params = stream_context_get_params($socket);
        $certificate = $params['options']['ssl']['peer_certificate'] ?? null;

        if ($certificate === null) {
            return null;
        }

        $parsed = @openssl_x509_parse($certificate);

        if (! is_array($parsed) || ! isset($parsed['validTo_time_t'])) {
            return null;
        }

        return gmdate('c', (int) $parsed['validTo_time_t']);
    }
}
