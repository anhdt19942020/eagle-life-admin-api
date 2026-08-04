<?php
namespace App\Console\Commands;
use App\Models\PrintifyShop;
use App\Services\Printify\PrintifySyncService;
use Illuminate\Console\Command;
class SyncPrintifyOrders extends Command { protected $signature = 'printify:sync-orders {--shop-id=} {--limit-pages=}'; protected $description = 'Sync Printify orders'; public function handle(PrintifySyncService $sync): int { $shops=$this->option('shop-id') ? PrintifyShop::where('printify_shop_id',$this->option('shop-id'))->get() : PrintifyShop::where('is_active',true)->get(); foreach ($shops as $shop) $sync->syncOrders($shop->printify_shop_id, $this->option('limit-pages') ? (int)$this->option('limit-pages') : null); return self::SUCCESS; } }
