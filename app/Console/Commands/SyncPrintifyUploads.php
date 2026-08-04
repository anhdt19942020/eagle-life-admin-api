<?php
namespace App\Console\Commands;
use App\Services\Printify\PrintifySyncService;
use Illuminate\Console\Command;
class SyncPrintifyUploads extends Command { protected $signature = 'printify:sync-uploads {--limit-pages=}'; protected $description = 'Sync Printify uploads'; public function handle(PrintifySyncService $sync): int { $sync->syncUploads($this->option('limit-pages') ? (int)$this->option('limit-pages') : null); return self::SUCCESS; } }
