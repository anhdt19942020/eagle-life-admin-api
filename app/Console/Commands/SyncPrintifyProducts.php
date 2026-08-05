<?php

namespace App\Console\Commands;

use App\Models\PrintifyShop;
use App\Services\Printify\PrintifySyncService;
use Illuminate\Console\Command;

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

            $product = $sync->syncProduct((int) $shopId, (string) $productId);
            $this->info("Synced product {$product->printify_product_id} ({$product->title}) with {$product->variants->count()} variants.");

            return self::SUCCESS;
        }

        $shops = $this->option('shop-id')
            ? PrintifyShop::where('printify_shop_id', $this->option('shop-id'))->get()
            : PrintifyShop::where('is_active', true)->get();

        $limitPages = $this->option('limit-pages') !== null && $this->option('limit-pages') !== ''
            ? (int) $this->option('limit-pages')
            : null;
        $maxProducts = $this->option('max-products') !== null && $this->option('max-products') !== ''
            ? (int) $this->option('max-products')
            : null;

        foreach ($shops as $shop) {
            $count = $sync->syncProducts($shop->printify_shop_id, $limitPages, $maxProducts);
            $this->info("Shop {$shop->printify_shop_id}: synced {$count} product(s).");
        }

        return self::SUCCESS;
    }
}
