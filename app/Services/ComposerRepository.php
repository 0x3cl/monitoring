<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Serves SENTINEL's integration packages as a Composer repository.
 *
 * Monitored applications install the agent straight from the SENTINEL
 * instance they already report to, so there is nothing to upload, no
 * Packagist account and no separate VCS host to maintain.
 */
class ComposerRepository
{
    public function __construct(
        protected IntegrationPackager $packager,
    ) {}

    /**
     * The repository root document Composer fetches first.
     *
     * @return array<string, mixed>
     */
    public function rootMetadata(): array
    {
        $packages = [];

        foreach ($this->availableSlugs() as $slug) {
            $definition = $this->packageDefinition($slug);
            $packages[$definition['name']][$definition['version']] = $definition;
        }

        return [
            'packages' => $packages,
            // Everything is inlined above; Composer needs no further lookups.
            'available-packages' => array_keys($packages),
        ];
    }

    /**
     * Composer metadata for one package, including where to download it.
     *
     * @return array<string, mixed>
     */
    public function packageDefinition(string $slug): array
    {
        $manifest = $this->manifest($slug);
        $version = (string) ($manifest['version'] ?? '1.0.0');
        $archive = $this->archivePath($slug);
        $checksum = sha1_file($archive);

        return array_merge(
            // Carry the package's own metadata through unchanged: requirements,
            // autoloading and Laravel auto-discovery all live here.
            array_intersect_key($manifest, array_flip([
                'name', 'description', 'type', 'license', 'require', 'autoload', 'extra',
            ])),
            [
                'version' => $version,
                'version_normalized' => $this->normalize($version),
                'dist' => [
                    'type' => 'zip',
                    'url' => $this->distUrl($manifest['name'], $version),
                    'reference' => $checksum,
                    'shasum' => $checksum,
                ],
            ]
        );
    }

    /**
     * Path to the built archive, rebuilt whenever the source changes so a
     * published checksum always matches the bytes that are served.
     */
    public function archivePath(string $slug): string
    {
        $source = $this->packager->pathFor($slug);
        $target = storage_path('app/packages/'.$this->packager->packageName($slug).'.zip');

        File::ensureDirectoryExists(dirname($target));

        if (! file_exists($target) || filemtime($target) < $this->sourceModifiedAt($source)) {
            File::copy($this->packager->archive($slug), $target);
        }

        return $target;
    }

    /**
     * Resolve a vendor/package pair back to an integration slug.
     */
    public function slugForPackage(string $name): ?string
    {
        foreach ($this->availableSlugs() as $slug) {
            if (($this->manifest($slug)['name'] ?? null) === $name) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function availableSlugs(): array
    {
        return array_values(array_filter(
            ['laravel'],
            fn (string $slug) => $this->packager->hasPackage($slug)
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(string $slug): array
    {
        $path = $this->packager->pathFor($slug).'/composer.json';

        if (! is_file($path)) {
            throw new RuntimeException("Integration package [{$slug}] has no composer.json.");
        }

        return (array) json_decode((string) file_get_contents($path), true);
    }

    public function distUrl(string $name, string $version): string
    {
        return rtrim((string) config('sentinel.packages.url') ?: (string) config('app.url'), '/')
            ."/packages/{$name}/{$version}.zip";
    }

    protected function sourceModifiedAt(string $source): int
    {
        $latest = 0;

        foreach (File::allFiles($source) as $file) {
            $latest = max($latest, $file->getMTime());
        }

        return $latest;
    }

    /**
     * Composer's normalized form: four numeric segments.
     */
    protected function normalize(string $version): string
    {
        $parts = array_map('intval', explode('.', preg_replace('/[^0-9.].*$/', '', $version) ?: '0'));
        $parts = array_pad(array_slice($parts, 0, 4), 4, 0);

        return implode('.', $parts);
    }
}
