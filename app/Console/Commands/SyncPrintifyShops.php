<?php
namespace App\Console\Commands;
use App\Services\Printify\PrintifySyncService;
use Illuminate\Console\Command;
class SyncPrintifyShops extends Command { protected $signature = 'printify:sync-shops'; protected $description = 'Sync Printify shops'; public function handle(PrintifySyncService $sync): int { $this->info("Synced {$sync->syncShops()} shops."); return self::SUCCESS; } }
