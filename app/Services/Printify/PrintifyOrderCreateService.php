<?php

namespace App\Services\Printify;

use App\Models\Order;
use App\Models\PrintifyOrder;
use App\Models\PrintifyShop;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PrintifyOrderCreateService
{
    public function __construct(
        private readonly PrintifyOrderPreviewService $preview,
        private readonly PrintifyClientFactory $factory,
    ) {}

    /**
     * @param  array<int, array{line_item_id:int, variant_id:int}>  $manualMappings
     * @return array{created: bool, printify_order: PrintifyOrder, remote: array, preview: array}
     */
    public function create(Order $order, PrintifyShop $shop, array $manualMappings = []): array
    {
        $externalId = (string) ($order->ebay_order_number ?: $order->ebay_order_id);

        $existing = PrintifyOrder::query()
            ->where('printify_shop_id', $shop->id)
            ->where('ebay_order_number', $externalId)
            ->first();

        if ($existing !== null) {
            return [
                'created' => false,
                'printify_order' => $existing,
                'remote' => [],
                'preview' => [],
            ];
        }

        $preview = $this->preview->preview($order, $shop, $manualMappings);
        if (! $preview['ready'] || $preview['payload'] === null) {
            throw new RuntimeException('Printify order payload is not ready: '.implode(' ', $preview['errors']));
        }

        $attemptKey = 'create:'.$shop->id.':'.$externalId;
        // preview() above already guarantees shop->account is non-null and active.
        $remote = $this->factory->for($shop->account)->post(
            "/shops/{$shop->printify_shop_id}/orders.json",
            $preview['payload']
        );

        $remoteId = (string) ($remote['id'] ?? '');
        if ($remoteId === '') {
            throw new RuntimeException('Printify create order response missing id.');
        }

        $printifyOrder = DB::transaction(function () use ($order, $shop, $externalId, $attemptKey, $remote, $remoteId) {
            $record = PrintifyOrder::create([
                'order_id' => $order->id,
                'printify_shop_id' => $shop->id,
                'printify_order_id' => $remoteId,
                'ebay_order_number' => $externalId,
                'status' => $remote['status'] ?? 'pending',
                'intent_state' => 'created',
                'has_conflict' => false,
                'attempt_key' => $attemptKey,
                'synced_at' => now(),
            ]);

            $order->forceFill([
                'printify_order_id' => $remoteId,
                'printify_created_at' => $order->printify_created_at ?? now(),
            ])->save();

            return $record;
        });

        return [
            'created' => true,
            'printify_order' => $printifyOrder,
            'remote' => $remote,
            'preview' => $preview,
        ];
    }
}
