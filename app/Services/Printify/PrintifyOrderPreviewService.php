<?php

namespace App\Services\Printify;

use App\Models\Order;
use App\Models\OrderLineItem;
use App\Models\PrintifyProductVariant;
use App\Models\PrintifyShop;
use RuntimeException;

class PrintifyOrderPreviewService
{
    /**
     * Build a Printify create-order payload without calling Printify.
     *
     * @param  array<int, array{line_item_id:int, variant_id:int}>  $manualMappings
     * @return array{ready: bool, shop_id: int, printify_shop_id: int, external_id: string, errors: list<string>, line_mappings: list<array<string, mixed>>, payload: ?array}
     */
    public function preview(Order $order, PrintifyShop $shop, array $manualMappings = []): array
    {
        if (! $shop->isReadyForCreation()) {
            throw new RuntimeException('Printify shop is not ready for order creation.');
        }

        $order->loadMissing(['fulfillmentAddress', 'lineItems']);

        $errors = [];
        $address = $order->fulfillmentAddress;
        if ($address === null) {
            $errors[] = 'Order has no fulfillment address.';
        } elseif (trim((string) $address->email) === '') {
            $errors[] = 'Fulfillment address is missing email.';
        } elseif (strlen((string) $address->country_code) !== 2) {
            $errors[] = 'Fulfillment address country_code must be ISO-2.';
        }

        if ($order->lineItems->isEmpty()) {
            $errors[] = 'Order has no line items.';
        }

        $manualByLineId = collect($manualMappings)->keyBy('line_item_id');
        $lineMappings = [];
        $payloadLines = [];

        foreach ($order->lineItems as $lineItem) {
            $resolved = $this->resolveVariant($shop, $lineItem, $manualByLineId->get($lineItem->id));
            $lineMappings[] = $resolved;

            if ($resolved['error'] !== null) {
                $errors[] = $resolved['error'];
                continue;
            }

            $payloadLines[] = [
                // Printify product ids are opaque strings (often hex ObjectIds); never cast to int.
                'product_id' => (string) $resolved['printify_product_id'],
                'variant_id' => (int) $resolved['printify_variant_id'],
                'quantity' => (int) $lineItem->quantity,
            ];
        }

        $ready = $errors === [] && $payloadLines !== [];
        $externalId = (string) ($order->ebay_order_number ?: $order->ebay_order_id);

        return [
            'ready' => $ready,
            'shop_id' => $shop->id,
            'printify_shop_id' => (int) $shop->printify_shop_id,
            'external_id' => $externalId,
            'errors' => $errors,
            'line_mappings' => $lineMappings,
            'payload' => $ready ? [
                'external_id' => $externalId,
                'label' => $externalId,
                'line_items' => $payloadLines,
                'shipping_method' => 1,
                'is_printify_express' => false,
                'send_shipping_notification' => false,
                'address_to' => $address->toPrintifyAddress(),
            ] : null,
        ];
    }

    /**
     * @param  array{line_item_id?: int, variant_id?: int}|null  $manual
     * @return array{line_item_id: int, sku: ?string, source: string, printify_variant_id: ?int, printify_product_id: ?string, error: ?string}
     */
    private function resolveVariant(PrintifyShop $shop, OrderLineItem $lineItem, ?array $manual): array
    {
        if ($manual !== null && isset($manual['variant_id'])) {
            $variant = PrintifyProductVariant::query()
                ->where('printify_variant_id', (string) $manual['variant_id'])
                ->where('is_enabled', true)
                ->whereHas('product', fn ($query) => $query->where('printify_shop_id', $shop->id))
                ->with('product')
                ->first();

            if ($variant === null) {
                return [
                    'line_item_id' => $lineItem->id,
                    'sku' => $lineItem->custom_label,
                    'source' => 'manual',
                    'printify_variant_id' => null,
                    'printify_product_id' => null,
                    'error' => "Line item {$lineItem->id}: manual variant_id {$manual['variant_id']} not found in shop.",
                ];
            }

            return [
                'line_item_id' => $lineItem->id,
                'sku' => $lineItem->custom_label,
                'source' => 'manual',
                'printify_variant_id' => (int) $variant->printify_variant_id,
                'printify_product_id' => (string) $variant->product->printify_product_id,
                'error' => null,
            ];
        }

        $sku = trim((string) $lineItem->custom_label);
        if ($sku === '') {
            $sku = trim((string) $lineItem->item_number);
        }
        if ($sku === '') {
            return [
                'line_item_id' => $lineItem->id,
                'sku' => null,
                'source' => 'unresolved',
                'printify_variant_id' => null,
                'printify_product_id' => null,
                'error' => "Line item {$lineItem->id}: missing Custom Label/SKU; provide a manual variant mapping.",
            ];
        }

        $matches = PrintifyProductVariant::query()
            ->where('sku', $sku)
            ->where('is_enabled', true)
            ->whereHas('product', fn ($query) => $query->where('printify_shop_id', $shop->id))
            ->with('product')
            ->get();

        if ($matches->isEmpty()) {
            return [
                'line_item_id' => $lineItem->id,
                'sku' => $sku,
                'source' => 'sku',
                'printify_variant_id' => null,
                'printify_product_id' => null,
                'error' => "Line item {$lineItem->id}: no enabled Printify variant with SKU [{$sku}].",
            ];
        }

        if ($matches->count() > 1) {
            return [
                'line_item_id' => $lineItem->id,
                'sku' => $sku,
                'source' => 'sku',
                'printify_variant_id' => null,
                'printify_product_id' => null,
                'error' => "Line item {$lineItem->id}: ambiguous SKU [{$sku}] matches {$matches->count()} variants; provide a manual mapping.",
            ];
        }

        $variant = $matches->first();

        return [
            'line_item_id' => $lineItem->id,
            'sku' => $sku,
            'source' => 'sku',
            'printify_variant_id' => (int) $variant->printify_variant_id,
            'printify_product_id' => (string) $variant->product->printify_product_id,
            'error' => null,
        ];
    }
}
