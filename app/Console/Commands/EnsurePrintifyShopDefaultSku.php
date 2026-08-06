<?php

namespace App\Console\Commands;

use App\Models\PrintifyShop;
use App\Services\Printify\PrintifyDefaultSkuEnsurer;
use Illuminate\Console\Command;

class EnsurePrintifyShopDefaultSku extends Command
{
    protected $signature = 'printify:ensure-default-sku
        {--shop-id= : Remote Printify shop id (optional; default = all active shops missing default_sku)}
        {--open-only : Limit to shops with is_open=true}
        {--seed-product : If shop has no enabled SKU, sync max 1 product from Printify then pick}
        {--dry-run : Report only, do not write}';

    protected $description = 'Backfill printify_shops.default_sku from a unique enabled local variant (optionally seed 1 product)';

    public function handle(PrintifyDefaultSkuEnsurer $ensurer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $seed = (bool) $this->option('seed-product');
        $openOnly = (bool) $this->option('open-only');
        $shopIdOption = $this->option('shop-id');
        $remoteShopId = ($shopIdOption !== null && $shopIdOption !== '')
            ? (int) $shopIdOption
            : null;

        $query = PrintifyShop::query()->where('is_active', true)->with('account')->orderBy('title');
        if ($openOnly) {
            $query->where('is_open', true);
        }
        if ($remoteShopId !== null) {
            $query->where('printify_shop_id', $remoteShopId);
        } else {
            $query->where(function ($q) {
                $q->whereNull('default_sku')->orWhere('default_sku', '');
            });
        }

        $shops = $query->get();
        if ($shops->isEmpty()) {
            $this->info('No shops to process.');

            return self::SUCCESS;
        }

        $set = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($shops as $shop) {
            $result = $ensurer->ensureForShop($shop, $seed, $dryRun);

            if ($result['status'] === 'set') {
                $prefix = $dryRun ? '[dry-run] would set' : 'Set';
                $this->info("{$prefix} shop {$shop->printify_shop_id} ({$shop->title}) default_sku={$result['sku']}");
                $set++;
                continue;
            }

            if ($result['status'] === 'failed') {
                $this->error("Shop {$shop->printify_shop_id}: {$result['reason']}");
                $failed++;
                continue;
            }

            $reason = $result['reason'] ?? 'skipped';
            if ($reason === 'already_set') {
                $this->line("Shop {$shop->printify_shop_id} already has default_sku={$result['sku']}");
            } elseif ($reason === 'inactive_or_missing_account') {
                $this->warn("Shop {$shop->printify_shop_id} ({$shop->title}): skip — inactive or missing account");
            } elseif ($reason === 'no_unique_enabled_sku') {
                $this->warn("Shop {$shop->printify_shop_id} ({$shop->title}): skip — no unique enabled SKU");
            } else {
                $this->warn("Shop {$shop->printify_shop_id} ({$shop->title}): skip — {$reason}");
            }
            $skipped++;
        }

        $this->newLine();
        $this->info("Done. set={$set} skipped={$skipped} failed={$failed}".($dryRun ? ' (dry-run)' : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
