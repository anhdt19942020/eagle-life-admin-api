<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('printify:sync-shops')->hourly()->withoutOverlapping();
Schedule::command('printify:sync-orders')->hourly()->withoutOverlapping();
Schedule::command('printify:sync-products')->hourly()->withoutOverlapping();
Schedule::command('printify:sync-uploads')->hourly()->withoutOverlapping();
