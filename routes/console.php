<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('printify:sync-shops')->hourly()->withoutOverlapping();
// Shops are account-serialized; bound each scheduled sync before the next 15-minute tick.
Schedule::command('printify:sync-orders --limit-pages=1 --sync-timeout=5')->everyFifteenMinutes()->withoutOverlapping();
// Full product catalog sync disabled: large shops timeout / bloat DB.
// Seed placeholders with: php artisan printify:sync-products --shop-id=… --product-id=…| --max-products=1
// Then set default_sku (UI or printify:ensure-default-sku).
// Schedule::command('printify:sync-products')->hourly()->withoutOverlapping();
Schedule::command('printify:sync-uploads')->hourly()->withoutOverlapping();
