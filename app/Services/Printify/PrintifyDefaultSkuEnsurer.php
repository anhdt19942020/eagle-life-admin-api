<?php

namespace App\Services\Printify;

use App\Models\PrintifyAccount;
use App\Models\PrintifyProduct;
use App\Models\PrintifyProductVariant;
use App\Models\PrintifyShop;
use Illuminate\Support\Facades\Log;
use Throwable;

class PrintifyDefaultSkuEnsurer
{
    public function __construct(private readonly PrintifySyncService $sync) {}

    /**
     * @return array{set: int, skipped: int, failed: int}
     */
    public function ensureForAccount(
        PrintifyAccount $account,
        bool $seedProduct = true,
        bool $dryRun = false,
        bool $openOnly = false,
        ?int $remoteShopId = null,
    ): array {
        $query = PrintifyShop::query()
            ->where('printify_account_id', $account->id)
            ->where('is_active', true)
            ->with('account')
            ->orderBy('title');

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

        $stats = ['set' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($query->get() as $shop) {
            $result = $this->ensureForShop($shop, $seedProduct, $dryRun);
            $stats[$result['status']]++;
        }

        return $stats;
    }

    /**
     * @return array{status: 'set'|'skipped'|'failed', sku: ?string, reason: ?string}
     */
    public function ensureForShop(
        PrintifyShop $shop,
        bool $seedProduct = true,
        bool $dryRun = false,
        bool $force = false,
    ): array {
        if ($force) {
            return $this->refreshForShop($shop, $seedProduct, $dryRun);
        }

        if (filled(trim((string) $shop->default_sku))) {
            return [
                'status' => 'skipped',
                'sku' => (string) $shop->default_sku,
                'reason' => 'already_set',
            ];
        }

        $gate = $this->preflightShop($shop);
        if ($gate !== null) {
            return $gate;
        }

        try {
            $sku = $this->pickUniqueEnabledSku($shop);

            if ($sku === null && $seedProduct) {
                if (! $dryRun) {
                    $this->sync->syncProducts($shop->account, (int) $shop->printify_shop_id, 1, 1);
                    $shop->refresh();
                }
                $sku = $this->pickUniqueEnabledSku($shop);
            }

            return $this->finishSku($shop, $sku, $dryRun, forced: false);
        } catch (Throwable $exception) {
            return $this->failed($shop, $exception);
        }
    }

    /**
     * Re-sync 1 product and overwrite default_sku (even when already set).
     *
     * @return array{status: 'set'|'skipped'|'failed', sku: ?string, reason: ?string}
     */
    public function refreshForShop(
        PrintifyShop $shop,
        bool $seedProduct = true,
        bool $dryRun = false,
    ): array {
        $gate = $this->preflightShop($shop);
        if ($gate !== null) {
            return $gate;
        }

        try {
            if ($seedProduct && ! $dryRun) {
                $this->sync->syncProducts($shop->account, (int) $shop->printify_shop_id, 1, 1);
                $shop->refresh();
            }

            $sku = $this->pickUniqueEnabledSkuFromNewestProduct($shop)
                ?? $this->pickUniqueEnabledSku($shop);

            return $this->finishSku($shop, $sku, $dryRun, forced: true);
        } catch (Throwable $exception) {
            return $this->failed($shop, $exception);
        }
    }

    /**
     * @return array{status: 'skipped', sku: ?string, reason: string}|null
     */
    private function preflightShop(PrintifyShop $shop): ?array
    {
        if (! $shop->is_active) {
            Log::info('printify_default_sku.skipped', [
                'printify_shop_id' => $shop->printify_shop_id,
                'reason' => 'inactive_shop',
            ]);

            return [
                'status' => 'skipped',
                'sku' => null,
                'reason' => 'inactive_shop',
            ];
        }

        $shop->loadMissing('account');

        if ($shop->account === null || ! $shop->account->is_active) {
            Log::info('printify_default_sku.skipped', [
                'printify_shop_id' => $shop->printify_shop_id,
                'reason' => 'inactive_or_missing_account',
            ]);

            return [
                'status' => 'skipped',
                'sku' => null,
                'reason' => 'inactive_or_missing_account',
            ];
        }

        return null;
    }

    /**
     * @return array{status: 'set'|'skipped', sku: ?string, reason: ?string}
     */
    private function finishSku(PrintifyShop $shop, ?string $sku, bool $dryRun, bool $forced): array
    {
        if ($sku === null) {
            Log::info('printify_default_sku.skipped', [
                'printify_shop_id' => $shop->printify_shop_id,
                'reason' => 'no_unique_enabled_sku',
                'force' => $forced,
            ]);

            return [
                'status' => 'skipped',
                'sku' => null,
                'reason' => 'no_unique_enabled_sku',
            ];
        }

        if (! $dryRun) {
            $shop->forceFill(['default_sku' => $sku])->save();
        }

        Log::info('printify_default_sku.set', [
            'printify_shop_id' => $shop->printify_shop_id,
            'default_sku' => $sku,
            'dry_run' => $dryRun,
            'force' => $forced,
        ]);

        return [
            'status' => 'set',
            'sku' => $sku,
            'reason' => $forced ? 'forced' : null,
        ];
    }

    /**
     * @return array{status: 'failed', sku: null, reason: string}
     */
    private function failed(PrintifyShop $shop, Throwable $exception): array
    {
        Log::warning('printify_default_sku.failed', [
            'printify_shop_id' => $shop->printify_shop_id,
            'message' => $exception->getMessage(),
        ]);

        return [
            'status' => 'failed',
            'sku' => null,
            'reason' => $exception->getMessage(),
        ];
    }

    public function pickUniqueEnabledSku(PrintifyShop $shop): ?string
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

    public function pickUniqueEnabledSkuFromNewestProduct(PrintifyShop $shop): ?string
    {
        $product = PrintifyProduct::query()
            ->where('printify_shop_id', $shop->id)
            ->orderByDesc('synced_at')
            ->orderByDesc('id')
            ->first();

        if ($product === null) {
            return null;
        }

        $skus = PrintifyProductVariant::query()
            ->where('printify_product_id', $product->id)
            ->where('is_enabled', true)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->pluck('sku');

        $unique = $skus->countBy()->filter(fn ($count) => $count === 1)->keys();

        return $unique->isEmpty() ? null : (string) $unique->first();
    }
}
