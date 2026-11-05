<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$availability = max(1, (int) config('sentinel.intervals.availability_check'));

// Connectivity is re-evaluated on the interval configured in config/sentinel.php.
Schedule::command('sentinel:refresh-connection-status')
    ->cron('*/'.$availability.' * * * *');

// Outbound heartbeat probes. The command only selects and queues - every probe
// itself runs on a worker, so a hung host can never delay the scheduler.
// withoutOverlapping guards against a dispatch cycle that outlives its slot.
Schedule::command('sentinel:dispatch-heartbeats')
    ->cron('*/'.$availability.' * * * *')
    ->withoutOverlapping();

// Per-day traffic rollups. Reuses the performance-analysis cadence, which this
// configuration already reserved for exactly this kind of periodic analysis.
Schedule::command('sentinel:aggregate-traffic')
    ->cron('*/'.max(1, (int) config('sentinel.intervals.performance_analysis')).' * * * *')
    ->withoutOverlapping();
