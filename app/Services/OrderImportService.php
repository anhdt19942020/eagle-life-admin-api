<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;

class OrderImportService
{
    const CHUNK_SIZE = 100;

    public function importFromArray(array $orders): array
    {
        $result = [
            'total'   => count($orders),
            'success' => 0,
            'failed'  => 0,
            'errors'  => [],
        ];

        foreach (array_chunk($orders, self::CHUNK_SIZE, true) as $chunk) {
            $this->processChunk($chunk, $result);
        }

        return $result;
    }

    private function processChunk(array $chunk, array &$result): void
    {
        // Pre-load seller/buyer maps from employee_codes in this chunk
        $buyerCodes  = array_filter(array_column($chunk, 'buyer_code'));
        $sellerCodes = array_filter(array_column($chunk, 'seller_code'));
        $allCodes    = array_unique(array_merge($buyerCodes, $sellerCodes));

        $userMap = User::whereIn('employee_code', $allCodes)
            ->pluck('id', 'employee_code');

        $toInsert = [];

        foreach ($chunk as $index => $item) {
            $rowNumber = $index + 2; // offset for display (row 1 = header)

            // Kiểm tra trùng ebay_order_id
            if (Order::where('ebay_order_id', $item['ebay_order_id'])->exists()) {
                $result['failed']++;
                $result['errors'][] = "Mục #{$rowNumber}: Mã eBay '{$item['ebay_order_id']}' đã tồn tại";
                continue;
            }

            $toInsert[] = [
                'ebay_order_id'       => $item['ebay_order_id'],
                'buyer_id'            => isset($item['buyer_code']) ? ($userMap[$item['buyer_code']] ?? null) : null,
                'seller_id'           => isset($item['seller_code']) ? ($userMap[$item['seller_code']] ?? null) : null,
                'ebay_created_at'     => $item['ebay_created_at'],
                'printify_created_at' => $item['printify_created_at'] ?? null,
                'printify_order_id'   => $item['printify_order_id'] ?? null,
                'created_at'          => now(),
                'updated_at'          => now(),
            ];
        }

        if (!empty($toInsert)) {
            Order::insert($toInsert);
            $result['success'] += count($toInsert);
        }
    }
}
