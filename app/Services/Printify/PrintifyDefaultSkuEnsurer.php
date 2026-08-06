<?php

namespace App\Services\Printify;

use App\Models\PrintifyAccount;
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
    public function ensureForShop(PrintifyShop $shop, bool $seedProduct = true, bool $dryRun = false): array
    {
        if (filled(trim((string) $shop->default_sku))) {
            return [
                'status' => 'skipped',
                'sku' => (string) $shop->default_sku,
                'reason' => 'already_set',
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

        try {
            $sku = $this->pickUniqueEnabledSku($shop);

            if ($sku === null && $seedProduct) {
                if (! $dryRun) {
                    $this->sync->syncProducts($shop->account, (int) $shop->printify_shop_id, 1, 1);
                    $shop->refresh();
                }
                $sku = $this->pickUniqueEnabledSku($shop);
            }

            if ($sku === null) {
                Log::info('printify_default_sku.skipped', [
                    'printify_shop_id' => $shop->printify_shop_id,
                    'reason' => 'no_unique_enabled_sku',
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
            ]);

            return [
                'status' => 'set',
                'sku' => $sku,
                'reason' => null,
            ];
        } catch (Throwable $exception) {
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
}
