<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Connectivity is re-evaluated on the interval configured in config/sentinel.php.
Schedule::command('sentinel:refresh-connection-status')
    ->cron('*/'.max(1, (int) config('sentinel.intervals.availability_check')).' * * * *');
