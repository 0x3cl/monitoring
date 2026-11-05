<?php

namespace App\Enums;

/**
 * Why a heartbeat probe reached the verdict it did.
 *
 * The point of the feature is not only to notice that an application stopped
 * answering, but to name the layer that failed: a refused TCP connection on a
 * reachable host means the application died, while an unresolvable name or an
 * unreachable host means the problem is below the application entirely.
 */
enum ProbeDiagnosis: string
{
    case Healthy = 'healthy';
    case DnsFailure = 'dns_failure';
    case ConnectionRefused = 'connection_refused';
    case ConnectionTimeout = 'connection_timeout';
    case TlsFailure = 'tls_failure';
    case HttpTimeout = 'http_timeout';
    case HttpServerError = 'http_server_error';
    case HttpClientError = 'http_client_error';
    case MaintenanceMode = 'maintenance_mode';
    case DependencyFailure = 'dependency_failure';
    case SlowResponse = 'slow_response';
    case ServerUnreachable = 'server_unreachable';
    case NotConfigured = 'not_configured';
    case Unknown = 'unknown';

    /**
     * The sentence the dashboard shows beside the status.
     *
     * Written for whoever is being paged at 3am: it says which layer failed and
     * what that implies, not merely what was observed.
     */
    public function explanation(): string
    {
        return match ($this) {
            self::Healthy => 'The application answered the health probe normally.',
            self::DnsFailure => 'The application hostname could not be resolved, so nothing was contacted.',
            self::ConnectionRefused => 'The server is reachable but refused the connection: the web server or application process is not running.',
            self::ConnectionTimeout => 'The connection attempt timed out without a response.',
            self::TlsFailure => 'The TLS handshake failed: the certificate may be expired, invalid or mismatched.',
            self::HttpTimeout => 'The connection succeeded but the application never finished responding, so it is running but hung.',
            self::HttpServerError => 'The application answered with a server error, so it is running but failing.',
            self::HttpClientError => 'The probe URL was rejected by the application, which usually means the health endpoint is misconfigured rather than down.',
            self::MaintenanceMode => 'The application is deliberately in maintenance mode.',
            self::DependencyFailure => 'The application is answering, but one of its own dependencies reported a failure.',
            self::SlowResponse => 'The application answered successfully but far slower than expected.',
            self::ServerUnreachable => 'The server itself did not answer, so the problem is the host or the network, not the application.',
            self::NotConfigured => 'No base URL is registered for this application, so it cannot be probed.',
            self::Unknown => 'The probe failed for a reason SENTINEL could not classify.',
        };
    }

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
