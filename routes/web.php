<?php

use App\Http\Controllers\Admin\ApplicationController;
use App\Http\Controllers\Admin\CredentialController;
use App\Http\Controllers\Admin\MonitoringEventController;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\Admin\ServerController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\PackageRepositoryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

Route::post('login', [SessionController::class, 'store'])
    ->middleware(['guest', 'throttle:sentinel-login'])
    ->name('login.store');

Route::post('logout', [SessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
|--------------------------------------------------------------------------
| Management API (consumed by the Vue dashboard)
|--------------------------------------------------------------------------
|
| Served through the web middleware group so session cookies and CSRF
| protection apply: the dashboard is same-origin with this application.
|
*/

Route::prefix('api/admin')->name('admin.')->group(function () {
    Route::get('me', [SessionController::class, 'me'])->middleware('auth')->name('me');

    Route::middleware(['auth', 'admin'])->group(function () {
        Route::get('overview', OverviewController::class)->name('overview');
        Route::get('meta', [OverviewController::class, 'meta'])->name('meta');

        Route::get('applications', [ApplicationController::class, 'index'])->name('applications.index');
        Route::post('applications', [ApplicationController::class, 'store'])->name('applications.store');
        Route::get('applications/{application}', [ApplicationController::class, 'show'])->name('applications.show');
        Route::patch('applications/{application}', [ApplicationController::class, 'update'])->name('applications.update');
        Route::delete('applications/{application}', [ApplicationController::class, 'destroy'])->name('applications.destroy');
        Route::get('applications/{application}/integration', [ApplicationController::class, 'integration'])->name('applications.integration');
        Route::post('applications/{application}/integration/reissue', [ApplicationController::class, 'reissueIntegration'])->name('applications.integration.reissue');
        Route::get('applications/{application}/integration/download', [ApplicationController::class, 'downloadIntegration'])->name('applications.integration.download');

        Route::get('applications/{application}/credentials', [CredentialController::class, 'index'])->name('credentials.index');
        Route::post('applications/{application}/credentials', [CredentialController::class, 'store'])->name('credentials.store');
        Route::delete('credentials/{credential}', [CredentialController::class, 'destroy'])->name('credentials.destroy');

        Route::get('events', [MonitoringEventController::class, 'index'])->name('events.index');
        Route::get('events/{event}', [MonitoringEventController::class, 'show'])->name('events.show');

        Route::get('servers', [ServerController::class, 'index'])->name('servers.index');
        Route::post('servers', [ServerController::class, 'store'])->name('servers.store');
        Route::patch('servers/{server}', [ServerController::class, 'update'])->name('servers.update');
        Route::delete('servers/{server}', [ServerController::class, 'destroy'])->name('servers.destroy');
    });
});

/*
|--------------------------------------------------------------------------
| Composer repository
|--------------------------------------------------------------------------
|
| SENTINEL hosts its own integration packages, so a monitored application
| installs the agent straight from the instance it reports to. Registered
| before the dashboard catch-all, which would otherwise swallow these paths.
|
*/

Route::middleware('sentinel.packages')->group(function () {
    Route::get('packages.json', [PackageRepositoryController::class, 'index'])->name('packages.index');
    Route::get('packages/{vendor}/{package}/{version}.zip', [PackageRepositoryController::class, 'download'])
        ->where(['vendor' => '[a-z0-9_.-]+', 'package' => '[a-z0-9_.-]+', 'version' => '[0-9]+\.[0-9]+\.[0-9]+'])
        ->name('packages.download');
});

/*
|--------------------------------------------------------------------------
| Dashboard shell
|--------------------------------------------------------------------------
|
| Every non-API path returns the single page application; Vue Router owns
| navigation from there.
|
*/

Route::view('/{any?}', 'app')
    ->where('any', '^(?!api/|packages).*$')
    ->name('dashboard');
