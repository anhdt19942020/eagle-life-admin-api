<?php

namespace App\Services\Printify;

use App\Models\Order;
use App\Models\PrintifyAccount;
use App\Models\PrintifyOrder;
use App\Models\PrintifyProduct;
use App\Models\PrintifyShop;
use App\Models\PrintifyUpload;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class PrintifySyncService
{
    public function __construct(private readonly PrintifyClientFactory $factory) {}

    public function syncShops(PrintifyAccount $account): int
    {
        return $this->accountLocked($account, function () use ($account): int {
            return DB::transaction(function () use ($account): int {
                $client = $this->factory->for($account);
                $remote = $client->get('/shops.json');
                $ids = collect($remote)->pluck('id')->map(fn ($id) => (int) $id);

                foreach ($remote as $shop) {
                    $remoteId = (int) $shop['id'];
                    if ($this->belongsToAnotherAccount($remoteId, $account)) {
                        throw new RuntimeException(
                            "Printify shop {$remoteId} already belongs to another account; sync aborted."
                        );
                    }
                }

                PrintifyShop::where('printify_account_id', $account->id)
                    ->whereNotIn('printify_shop_id', $ids)
                    ->update(['is_active' => false]);

                return collect($remote)
                    ->map(function (array $shop) use ($account) {
                        $remoteId = (int) $shop['id'];
                        $local = PrintifyShop::where('printify_shop_id', $remoteId)->first();
                        $reactivated = $local === null || ! $local->is_active;
                        $attributes = [
                            'printify_account_id' => $account->id,
                            'title' => $shop['title'],
                            'is_active' => true,
                            'synced_at' => now(),
                        ];
                        if ($reactivated) {
                            $attributes += [
                                'orders_sync_state' => 'pending',
                                'orders_sync_completed_at' => null,
                                'orders_sync_watermark' => null,
                                'manual_approval_confirmed_by' => null,
                                'manual_approval_confirmed_at' => null,
                            ];
                        }

                        // Unique on printify_shop_id — upsert, never insert duplicate.
                        return PrintifyShop::updateOrCreate(['printify_shop_id' => $remoteId], $attributes);
                    })->count();
            });
        });
    }

    public function syncProducts(PrintifyAccount $account, int $remoteShopId, ?int $limitPages = null, ?int $maxProducts = null): int
    {
        return $this->accountLocked($account, fn () => $this->locked($account, $remoteShopId, function (PrintifyShop $shop) use ($account, $remoteShopId, $limitPages, $maxProducts): int {
            $client = $this->factory->for($account);
            $pages = $this->pages($client, "/shops/{$remoteShopId}/products.json", $limitPages);
            $synced = 0;
            foreach ($pages['items'] as $product) {
                $this->upsertProduct($shop, $product);
                $synced++;
                if ($maxProducts !== null && $synced >= $maxProducts) {
                    break;
                }
            }

            return $synced;
        }));
    }

    /**
     * Sync a single Printify product (+ variants) into the local catalog.
     * Prefer this for setting shop default_sku without pulling the full shop catalog.
     */
    public function syncProduct(PrintifyAccount $account, int $remoteShopId, string $remoteProductId): PrintifyProduct
    {
        return $this->accountLocked($account, fn () => $this->locked($account, $remoteShopId, function (PrintifyShop $shop) use ($account, $remoteShopId, $remoteProductId): PrintifyProduct {
            $client = $this->factory->for($account);
            $product = $client->get("/shops/{$remoteShopId}/products/{$remoteProductId}.json");
            if (! is_array($product) || ! isset($product['id'])) {
                throw new RuntimeException("Printify product [{$remoteProductId}] not found for shop {$remoteShopId}.");
            }

            return $this->upsertProduct($shop, $product);
        }));
    }

    private function upsertProduct(PrintifyShop $shop, array $product): PrintifyProduct
    {
        $local = PrintifyProduct::updateOrCreate(
            ['printify_shop_id' => $shop->id, 'printify_product_id' => (string) $product['id']],
            [
                'title' => $product['title'] ?? null,
                'status' => $product['visible'] ?? null,
                'blueprint_id' => (string) ($product['blueprint_id'] ?? ''),
                'print_provider_id' => (string) ($product['print_provider_id'] ?? ''),
                'synced_at' => now(),
            ],
        );

        foreach ($product['variants'] ?? [] as $variant) {
            $local->variants()->updateOrCreate(
                ['printify_variant_id' => (string) $variant['id']],
                [
                    'sku' => $variant['sku'] ?? null,
                    'title' => $variant['title'] ?? null,
                    'is_enabled' => $variant['is_enabled'] ?? true,
                    'price' => isset($variant['price']) ? $variant['price'] / 100 : null,
                ],
            );
        }

        return $local->load('variants');
    }

    public function syncOrders(PrintifyAccount $account, int $remoteShopId, ?int $limitPages = null): int
    {
        return $this->accountLocked($account, fn () => $this->locked($account, $remoteShopId, function (PrintifyShop $shop) use ($account, $remoteShopId, $limitPages): int {
            $shop->update(['orders_sync_state' => 'syncing']);

            try {
                $client = $this->factory->for($account);
                $pages = $this->pages($client, "/shops/{$remoteShopId}/orders.json", $limitPages);
                $externalIds = collect($pages['items'])->pluck('external_id')->filter()->unique();
                $conflictedExternalIds = PrintifyOrder::whereIn('ebay_order_number', $externalIds)->where('printify_shop_id', '!=', $shop->id)->pluck('ebay_order_number')->all();
                foreach ($pages['items'] as $remote) {
                    $externalId = $remote['external_id'] ?? null;
                    $hasConflict = $externalId !== null && in_array($externalId, $conflictedExternalIds, true);
                    if ($hasConflict) {
                        PrintifyOrder::where('ebay_order_number', $externalId)->where('printify_shop_id', '!=', $shop->id)->update(['has_conflict' => true, 'intent_state' => 'conflict']);
                    }
                    PrintifyOrder::updateOrCreate(
                        ['printify_shop_id' => $shop->id, 'printify_order_id' => (string) $remote['id']],
                        ['ebay_order_number' => $externalId, 'status' => $remote['status'] ?? null, 'has_conflict' => $hasConflict, 'intent_state' => $hasConflict ? 'conflict' : 'synced', 'synced_at' => now()],
                    );
                }

                $this->linkOrderIds($shop);

                if (! $pages['exhaustive'] || $conflictedExternalIds !== []) {
                    $shop->update(['orders_sync_state' => $conflictedExternalIds === [] ? 'incomplete' : 'conflict']);

                    return count($pages['items']);
                }

                $shop->update(['orders_sync_state' => 'complete', 'orders_sync_completed_at' => now(), 'orders_sync_watermark' => now()->toIso8601String()]);

                return count($pages['items']);
            } catch (Throwable $exception) {
                $shop->update(['orders_sync_state' => 'failed']);
                throw $exception;
            }
        }));
    }

    private function linkOrderIds(PrintifyShop $shop): void
    {
        $unlinked = PrintifyOrder::where('printify_shop_id', $shop->id)
            ->whereNull('order_id')
            ->whereNotNull('ebay_order_number')
            ->pluck('ebay_order_number', 'id');

        if ($unlinked->isEmpty()) {
            return;
        }

        $orderMap = Order::whereIn('ebay_order_id', $unlinked->values())
            ->pluck('id', 'ebay_order_id');

        foreach ($unlinked as $printifyOrderId => $ebayOrderNumber) {
            $orderId = $orderMap[$ebayOrderNumber] ?? null;
            if ($orderId === null) {
                continue;
            }
            PrintifyOrder::where('id', $printifyOrderId)->update(['order_id' => $orderId]);
        }
    }

    public function syncUploads(PrintifyAccount $account, ?int $limitPages = null): int
    {
        return $this->accountLocked($account, function () use ($account, $limitPages): int {
            $client = $this->factory->for($account);
            $pages = $this->pages($client, '/uploads.json', $limitPages);
            foreach ($pages['items'] as $upload) {
                PrintifyUpload::updateOrCreate(
                    ['printify_upload_id' => (string) $upload['id']],
                    ['file_name' => $upload['file_name'] ?? null, 'preview_url' => $upload['preview_url'] ?? null, 'synced_at' => now()],
                );
            }

            return count($pages['items']);
        });
    }

    private function pages(PrintifyClient $client, string $path, ?int $limitPages): array
    {
        $items = [];
        $exhaustive = $limitPages === null;

        for ($page = 1; $limitPages === null || $page <= $limitPages; $page++) {
            $response = $client->get($path, ['page' => $page, 'limit' => 50]);
            if (! array_key_exists('data', $response) || ! is_array($response['data'])) {
                throw new RuntimeException('Printify pagination response is invalid.');
            }

            $data = $response['data'];
            $items = array_merge($items, $data);
            $lastPage = $response['last_page'] ?? $response['meta']['last_page'] ?? null;
            if (($lastPage !== null && $page >= (int) $lastPage) || count($data) < 50) {
                break;
            }
            if ($limitPages !== null && $page === $limitPages) {
                $exhaustive = false;
            }
        }

        return ['items' => $items, 'exhaustive' => $exhaustive];
    }

    private function belongsToAnotherAccount(int $remoteShopId, PrintifyAccount $account): bool
    {
        $ownerAccountId = PrintifyShop::where('printify_shop_id', $remoteShopId)->value('printify_account_id');

        return $ownerAccountId !== null && (int) $ownerAccountId !== $account->id;
    }

    private function locked(PrintifyAccount $account, int $remoteShopId, callable $callback): mixed
    {
        $lock = Cache::lock(
            "printify:sync:shop:{$account->id}:{$remoteShopId}",
            (int) config('services.printify.sync_lock_seconds')
        );
        if (! $lock->get()) {
            throw new RuntimeException('A sync is already running for this shop.');
        }
        try {
            $shop = PrintifyShop::where('printify_shop_id', $remoteShopId)->firstOrFail();
            if ($shop->printify_account_id !== $account->id) {
                throw new RuntimeException("Shop {$remoteShopId} does not belong to the selected account.");
            }

            return $callback($shop);
        } finally {
            $lock->release();
        }
    }

    private function accountLocked(PrintifyAccount $account, callable $callback): mixed
    {
        $lock = Cache::lock("printify:sync:account:{$account->id}", (int) config('services.printify.sync_lock_seconds'));
        if (! $lock->get()) {
            throw new RuntimeException('A Printify account sync is already running.');
        }
        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
