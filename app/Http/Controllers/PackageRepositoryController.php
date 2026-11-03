<?php

namespace App\Http\Controllers;

use App\Services\ComposerRepository;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The Composer repository endpoints.
 *
 * Composer fetches /packages.json, then downloads the dist archive it points
 * at. Both are plain HTTP, so a monitored application needs nothing beyond
 * the SENTINEL URL it already reports to.
 */
class PackageRepositoryController extends Controller
{
    public function __construct(
        protected ComposerRepository $repository,
    ) {}

    public function index(): JsonResponse
    {
        return new JsonResponse($this->repository->rootMetadata());
    }

    public function download(string $vendor, string $package, string $version): BinaryFileResponse
    {
        $slug = $this->repository->slugForPackage("{$vendor}/{$package}");

        abort_if($slug === null, 404, 'Unknown package.');

        $definition = $this->repository->packageDefinition($slug);

        abort_unless($definition['version'] === $version, 404, 'Unknown package version.');

        return response()->download(
            $this->repository->archivePath($slug),
            "{$package}-{$version}.zip",
            ['Content-Type' => 'application/zip']
        );
    }
}
