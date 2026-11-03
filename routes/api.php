<?php

use App\Http\Controllers\Api\EventIngestionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Ingestion API
|--------------------------------------------------------------------------
|
| Consumed by monitored applications, not by the dashboard. Every route is
| authenticated with the application's own credential and rate limited per
| credential.
|
*/

Route::prefix('v1')
    ->middleware(['sentinel.size', 'sentinel.app', 'throttle:sentinel-ingest'])
    ->group(function () {
        Route::get('ping', [EventIngestionController::class, 'ping'])->name('api.ping');
        Route::post('events', [EventIngestionController::class, 'store'])->name('api.events.store');
        Route::post('events/batch', [EventIngestionController::class, 'storeBatch'])->name('api.events.batch');
    });
