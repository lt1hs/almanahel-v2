<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('alerts:generate')->hourly();
Schedule::command('receivables:mark-overdue')->dailyAt('01:15');
Schedule::command('ledger:reconcile')->dailyAt('02:00');
