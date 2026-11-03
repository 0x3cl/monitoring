<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Finder\Finder;
use ZipArchive;

/**
 * Packages an integration's source into a downloadable archive.
 *
 * The generated install instructions reference a Composer package, so that
 * package has to be obtainable: this is where administrators actually get the
 * files from.
 */
class IntegrationPackager
{
    /**
     * Integration slugs that ship source, mapped to their directory under
     * packages/. Slugs absent from this list have no downloadable agent yet.
     *
     * @var array<string, string>
     */
    protected array $packages = [
        'laravel' => 'sentinel-agent',
    ];

    public function hasPackage(string $slug): bool
    {
        return isset($this->packages[$slug]) && is_dir($this->pathFor($slug));
    }

    public function packageName(string $slug): ?string
    {
        return $this->packages[$slug] ?? null;
    }

    public function pathFor(string $slug): string
    {
        return base_path('packages/'.($this->packages[$slug] ?? ''));
    }

    /**
     * Build a zip of the agent source and return its temporary path. The
     * caller is responsible for deleting it after the download.
     */
    public function archive(string $slug): string
    {
        if (! $this->hasPackage($slug)) {
            throw new RuntimeException("No downloadable integration package for [{$slug}].");
        }

        $source = $this->pathFor($slug);
        $name = $this->packages[$slug];
        $target = tempnam(sys_get_temp_dir(), 'sentinel-').'.zip';

        $zip = new ZipArchive;

        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the integration archive.');
        }

        $files = Finder::create()
            ->files()
            ->in($source)
            ->ignoreDotFiles(false)
            ->exclude(['vendor', 'node_modules'])
            ->notName('composer.lock');

        foreach ($files as $file) {
            $zip->addFile(
                $file->getRealPath(),
                $name.'/'.str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname())
            );
        }

        $zip->close();

        return $target;
    }

    public function fileName(string $slug): string
    {
        return ($this->packages[$slug] ?? 'integration').'.zip';
    }
}
