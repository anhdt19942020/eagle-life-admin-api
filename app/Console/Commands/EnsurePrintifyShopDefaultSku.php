<?php

namespace App\Console\Commands;

use App\Models\PrintifyProductVariant;
use App\Models\PrintifyShop;
use App\Services\Printify\PrintifySyncService;
use Illuminate\Console\Command;
use Throwable;

class EnsurePrintifyShopDefaultSku extends Command
{
    protected $signature = 'printify:ensure-default-sku
        {--shop-id= : Remote Printify shop id (optional; default = all active shops missing default_sku)}
        {--open-only : Limit to shops with is_open=true}
        {--seed-product : If shop has no enabled SKU, sync max 1 product from Printify then pick}
        {--dry-run : Report only, do not write}';

    protected $description = 'Backfill printify_shops.default_sku from a unique enabled local variant (optionally seed 1 product)';

    public function handle(PrintifySyncService $sync): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $seed = (bool) $this->option('seed-product');

        $query = PrintifyShop::query()->where('is_active', true);
        if ($this->option('open-only')) {
            $query->where('is_open', true);
        }
        if ($this->option('shop-id') !== null && $this->option('shop-id') !== '') {
            $query->where('printify_shop_id', $this->option('shop-id'));
        } else {
            $query->where(function ($q) {
                $q->whereNull('default_sku')->orWhere('default_sku', '');
            });
        }

        $shops = $query->orderBy('title')->get();
        if ($shops->isEmpty()) {
            $this->info('No shops to process.');

            return self::SUCCESS;
        }

        $set = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($shops as $shop) {
            if (filled(trim((string) $shop->default_sku)) && $this->option('shop-id')) {
                $this->line("Shop {$shop->printify_shop_id} already has default_sku={$shop->default_sku}");
                $skipped++;
                continue;
            }

            try {
                $sku = $this->pickUniqueEnabledSku($shop);
                if ($sku === null && $seed) {
                    $this->warn("Shop {$shop->printify_shop_id} ({$shop->title}): no local SKU — seeding 1 product…");
                    if (! $dryRun) {
                        $sync->syncProducts((int) $shop->printify_shop_id, 1, 1);
                        $shop->refresh();
                    }
                    $sku = $this->pickUniqueEnabledSku($shop);
                }

                if ($sku === null) {
                    $this->warn("Shop {$shop->printify_shop_id} ({$shop->title}): skip — no unique enabled SKU");
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $this->info("[dry-run] would set shop {$shop->printify_shop_id} ({$shop->title}) default_sku={$sku}");
                } else {
                    $shop->forceFill(['default_sku' => $sku])->save();
                    $this->info("Set shop {$shop->printify_shop_id} ({$shop->title}) default_sku={$sku}");
                }
                $set++;
            } catch (Throwable $exception) {
                $failed++;
                $this->error("Shop {$shop->printify_shop_id}: {$exception->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Done. set={$set} skipped={$skipped} failed={$failed}".($dryRun ? ' (dry-run)' : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function pickUniqueEnabledSku(PrintifyShop $shop): ?string
    {
        $skus = PrintifyProductVariant::query()
            ->where('is_enabled', true)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->whereHas('product', fn ($q) => $q->where('printify_shop_id', $shop->id))
            ->pluck('sku');

        $unique = $skus->countBy()->filter(fn ($count) => $count === 1)->keys();

        return $unique->isEmpty() ? null : (string) $unique->first();
    }
}
