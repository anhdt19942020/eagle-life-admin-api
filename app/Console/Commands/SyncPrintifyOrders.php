<?php

namespace App\Console\Commands;

use App\Models\PrintifyShop;
use App\Services\Printify\PrintifySyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncPrintifyOrders extends Command
{
    protected $signature = 'printify:sync-orders {--shop-id=} {--limit-pages=}';

    protected $description = 'Sync Printify orders for active shops (account-scoped, sequential)';

    public function handle(PrintifySyncService $sync): int
    {
        $shops = $this->option('shop-id')
            ? PrintifyShop::with('account')->where('printify_shop_id', $this->option('shop-id'))->get()
            : PrintifyShop::with('account')->where('is_active', true)->orderBy('id')->get();

        $limitPages = $this->option('limit-pages') !== null && $this->option('limit-pages') !== ''
            ? (int) $this->option('limit-pages')
            : null;

        $synced = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($shops as $shop) {
            $account = $shop->account;
            if ($account === null || ! $account->is_active) {
                $skipped++;
                $this->warn("Shop {$shop->printify_shop_id}: skipped (inactive or missing account).");
                continue;
            }

            try {
                $count = $sync->syncOrders($account, (int) $shop->printify_shop_id, $limitPages);
                $synced += $count;
                $this->info("Shop {$shop->printify_shop_id}: synced {$count} order(s).");
            } catch (Throwable $exception) {
                $failed++;
                $this->error("Shop {$shop->printify_shop_id}: {$exception->getMessage()}");
            }
        }

        $this->info("Done. orders={$synced} skipped_accounts={$skipped} failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
