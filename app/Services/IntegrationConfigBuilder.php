<?php

namespace App\Services;

use App\Models\Application;

/**
 * Produces the configuration and installation instructions an administrator
 * needs after registering an application.
 *
 * The integration packages themselves (dost-ptri/sentinel-agent and friends)
 * arrive in later phases; what is generated here is the configuration contract
 * they consume, which is identical for every technology.
 */
class IntegrationConfigBuilder
{
    public function __construct(
        protected IntegrationPackager $packager,
        protected ComposerRepository $repository,
    ) {}

    /**
     * The machine-readable configuration payload.
     *
     * @return array<string, mixed>
     */
    public function configFor(Application $application, ?string $plainTextToken = null): array
    {
        $config = $application->monitoringConfig();

        return [
            'application_id' => $application->app_code,
            'application_name' => $application->name,
            'environment' => $application->environment->value,
            'endpoint' => $this->ingestionEndpoint(),
            'credential_header' => config('sentinel.credentials.header'),
            // Null unless a credential was just issued: existing credentials
            // are not recoverable.
            'credential' => $plainTextToken,
            'features' => $config['features'],
            'thresholds' => $config['thresholds'],
            'excluded_routes' => $config['excluded_routes'],
        ];
    }

    /**
     * A copy-paste .env block for the monitored application.
     */
    public function envSnippet(Application $application, ?string $plainTextToken = null): string
    {
        $config = $application->monitoringConfig();

        $lines = [
            'SENTINEL_ENABLED=true',
            'SENTINEL_APPLICATION_ID='.$application->app_code,
            'SENTINEL_ENDPOINT='.$this->ingestionEndpoint(),
            'SENTINEL_KEY='.($plainTextToken ?? '<your-application-key>'),
            'SENTINEL_SLOW_REQUEST_MS='.$config['thresholds']['slow_request_ms'],
            'SENTINEL_SAMPLE_RATE='.$config['thresholds']['sample_rate'],
        ];

        foreach ($config['features'] as $feature => $enabled) {
            $lines[] = 'SENTINEL_FEATURE_'.strtoupper($feature).'='.($enabled ? 'true' : 'false');
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Human-readable install steps, specific to the registered technology.
     *
     * @return array<int, array{title: string, body: string, language?: string}>
     */
    public function instructionsFor(Application $application, ?string $plainTextToken = null): array
    {
        $slug = $application->integrationType->slug;

        $common = [
            [
                'title' => 'Add the configuration',
                'body' => $this->envSnippet($application, $plainTextToken),
                'language' => 'ini',
            ],
            [
                'title' => 'Verify the connection',
                'body' => $slug === 'laravel'
                    ? 'php artisan sentinel:test --event'
                    : $this->verifyCommand($application, $plainTextToken),
                'language' => 'bash',
            ],
        ];

        $install = match ($slug) {
            'laravel' => [
                'title' => 'Install the agent package',
                'body' => implode(PHP_EOL, [
                    '# SENTINEL hosts the agent itself — nothing to download or upload.',
                    'composer config repositories.sentinel composer '.$this->repositoryUrl(),
                    'composer require '.($application->integrationType->package_name ?? 'dost-ptri/sentinel-agent'),
                    '',
                    '# Optional: publish the config to customise thresholds',
                    'php artisan vendor:publish --tag=sentinel-config',
                ]),
                'language' => 'bash',
            ],
            'linux-agent' => [
                'title' => 'Point Fluent Bit at SENTINEL',
                'body' => $this->fluentBitSnippet($application),
                'language' => 'ini',
            ],
            default => [
                'title' => 'Submit events directly',
                'body' => $this->genericSnippet($application, $plainTextToken),
                'language' => 'bash',
            ],
        };

        return array_values(array_merge([$install], $common));
    }

    /**
     * Everything the dashboard shows on an application's integration tab.
     *
     * @return array<string, mixed>
     */
    public function bundleFor(Application $application, ?string $plainTextToken = null): array
    {
        return [
            'integration_type' => $application->integrationType->slug,
            'integration_type_name' => $application->integrationType->name,
            'integration_version' => $application->integrationVersion?->version,
            'package_name' => $application->integrationType->package_name,
            'has_download' => $this->packager->hasPackage($application->integrationType->slug),
            'repository_url' => $this->packager->hasPackage($application->integrationType->slug)
                ? $this->repositoryUrl()
                : null,
            'config' => $this->configFor($application, $plainTextToken),
            'env' => $this->envSnippet($application, $plainTextToken),
            'instructions' => $this->instructionsFor($application, $plainTextToken),
        ];
    }

    /**
     * The Composer repository root this instance serves.
     */
    protected function repositoryUrl(): string
    {
        return rtrim((string) config('sentinel.packages.url') ?: (string) config('app.url'), '/');
    }

    protected function ingestionEndpoint(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/events';
    }

    protected function verifyCommand(Application $application, ?string $plainTextToken): string
    {
        $header = config('sentinel.credentials.header');
        $token = $plainTextToken ?? '<your-application-key>';
        $pingUrl = rtrim((string) config('app.url'), '/').'/api/v1/ping';

        return "curl -s -X GET {$pingUrl} \\\n  -H '{$header}: {$token}'";
    }

    /**
     * A working submission for applications with no agent package yet.
     */
    protected function genericSnippet(Application $application, ?string $plainTextToken): string
    {
        $header = config('sentinel.credentials.header');
        $token = $plainTextToken ?? '<your-application-key>';
        $endpoint = $this->ingestionEndpoint();

        return implode(PHP_EOL, [
            "curl -X POST {$endpoint} \\",
            "  -H '{$header}: {$token}' \\",
            "  -H 'Content-Type: application/json' \\",
            "  -d '{",
            '        "application_id": "'.$application->app_code.'",',
            '        "event_type": "http_request",',
            '        "severity": "ERROR",',
            '        "timestamp": "'.now()->utc()->toIso8601ZuluString().'",',
            '        "data": {"method": "POST", "endpoint": "/api/orders", "http_status": 500}',
            "      }'",
        ]);
    }

    protected function fluentBitSnippet(Application $application): string
    {
        $header = config('sentinel.credentials.header');
        $url = parse_url($this->ingestionEndpoint());

        return implode(PHP_EOL, [
            '[OUTPUT]',
            '    Name        http',
            '    Match       *',
            '    Host        '.($url['host'] ?? 'localhost'),
            '    Port        '.($url['port'] ?? ($url['scheme'] === 'https' ? 443 : 80)),
            '    URI         '.($url['path'] ?? '/api/v1/events'),
            '    Format      json',
            '    Header      '.$header.' <your-application-key>',
            '    Header      X-Sentinel-Application '.$application->app_code,
        ]);
    }
}
