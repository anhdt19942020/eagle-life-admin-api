<?php

namespace App\Console\Commands;

use App\Models\PrintifyShop;
use App\Services\Printify\PrintifySyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncPrintifyProducts extends Command
{
    protected $signature = 'printify:sync-products
        {--shop-id= : Remote Printify shop id}
        {--product-id= : Sync only this Printify product id (requires --shop-id)}
        {--limit-pages= : Cap list pagination when syncing a shop catalog}
        {--max-products= : Stop after N products when listing (e.g. 1 for a default SKU seed)}';

    protected $description = 'Sync Printify products (prefer --product-id or --max-products=1 for defaults)';

    public function handle(PrintifySyncService $sync): int
    {
        $productId = $this->option('product-id');
        if ($productId !== null && $productId !== '') {
            $shopId = $this->option('shop-id');
            if ($shopId === null || $shopId === '') {
                $this->error('--product-id requires --shop-id.');

                return self::FAILURE;
            }

            $shop = PrintifyShop::with('account')->where('printify_shop_id', (int) $shopId)->first();
            if ($shop === null || $shop->account === null || ! $shop->account->is_active) {
                $this->error("Shop {$shopId} is missing or its Printify account is inactive.");

                return self::FAILURE;
            }

            $product = $sync->syncProduct($shop->account, (int) $shop->printify_shop_id, (string) $productId);
            $this->info("Synced product {$product->printify_product_id} ({$product->title}) with {$product->variants->count()} variants.");

            return self::SUCCESS;
        }

        $shops = $this->option('shop-id')
            ? PrintifyShop::with('account')->where('printify_shop_id', $this->option('shop-id'))->get()
            : PrintifyShop::with('account')->where('is_active', true)->orderBy('id')->get();

        $limitPages = $this->option('limit-pages') !== null && $this->option('limit-pages') !== ''
            ? (int) $this->option('limit-pages')
            : null;
        $maxProducts = $this->option('max-products') !== null && $this->option('max-products') !== ''
            ? (int) $this->option('max-products')
            : null;

        $failed = 0;
        $skipped = 0;

        foreach ($shops as $shop) {
            if ($shop->account === null || ! $shop->account->is_active) {
                $skipped++;
                $this->warn("Shop {$shop->printify_shop_id}: skipped (inactive or missing account).");
                continue;
            }

            try {
                $count = $sync->syncProducts($shop->account, (int) $shop->printify_shop_id, $limitPages, $maxProducts);
                $this->info("Shop {$shop->printify_shop_id}: synced {$count} product(s).");
            } catch (Throwable $exception) {
                $failed++;
                $this->error("Shop {$shop->printify_shop_id}: {$exception->getMessage()}");
            }
        }

        $this->info("Done. skipped_accounts={$skipped} failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
