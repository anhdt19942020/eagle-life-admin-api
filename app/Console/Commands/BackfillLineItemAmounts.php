<?php

namespace App\Console\Commands;

use App\Models\OrderLineItem;
use Illuminate\Console\Command;

class BackfillLineItemAmounts extends Command
{
    protected $signature = 'orders:backfill-amounts';
    protected $description = 'Backfill shipping_amount and total_amount from ebay_raw JSON';

    public function handle(): int
    {
        $updated = 0;

        OrderLineItem::whereNotNull('ebay_raw')
            ->where(fn ($q) => $q->whereNull('total_amount')->orWhereNull('shipping_amount'))
            ->chunkById(200, function ($items) use (&$updated) {
                foreach ($items as $item) {
                    $raw = $item->ebay_raw;
                    if (!is_array($raw)) {
                        continue;
                    }

                    $shipping = $this->money($raw['Shipping And Handling'] ?? null);
                    $total = $this->money($raw['Total Price'] ?? null);

                    if ($shipping === null && $total === null) {
                        continue;
                    }

                    $item->update(array_filter([
                        'shipping_amount' => $shipping,
                        'total_amount' => $total,
                    ], fn ($v) => $v !== null));

                    $updated++;
                }
            });

        $this->info("Backfilled {$updated} line items.");

        return self::SUCCESS;
    }

    private function money(?string $value): ?float
    {
        $value = trim((string) $value);
        return $value === '' ? null : (float) str_replace(['$', ','], '', $value);
    }
}
