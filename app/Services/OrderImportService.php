<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;

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

            $buyerId = null;
            if (!empty($item['buyer_code'])) {
                if (isset($userMap[$item['buyer_code']])) {
                    $buyerId = $userMap[$item['buyer_code']];
                } else {
                    $result['errors'][] = "Mục #{$rowNumber} [{$item['ebay_order_id']}]: Mã buyer '{$item['buyer_code']}' không tồn tại trong hệ thống";
                }
            }

            $sellerId = null;
            if (!empty($item['seller_code'])) {
                if (isset($userMap[$item['seller_code']])) {
                    $sellerId = $userMap[$item['seller_code']];
                } else {
                    $result['errors'][] = "Mục #{$rowNumber} [{$item['ebay_order_id']}]: Mã seller '{$item['seller_code']}' không tồn tại trong hệ thống";
                }
            }

            $toInsert[] = [
                'ebay_order_id'       => $item['ebay_order_id'],
                'buyer_id'            => $buyerId,
                'seller_id'           => $sellerId,
                'ebay_created_at'     => $this->parseDate($item['ebay_created_at']),
                'printify_created_at' => isset($item['printify_created_at']) ? $this->parseDate($item['printify_created_at']) : null,
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

    /**
     * Parse flexible date formats:
     * - d/m/Y   → 1/3/2026 or 01/03/2026
     * - Standard → 2026-03-01, 2026-03-01 08:30:00, ISO 8601
     */
    private function parseDate(?string $date): ?string
    {
        if (empty($date)) {
            return null;
        }

        // Detect d/m/Y or d/m/Y H:i:s
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(.*)$#', $date, $m)) {
            $normalized = sprintf('%04d-%02d-%02d%s', $m[3], $m[2], $m[1], $m[4]);
            return Carbon::parse($normalized)->toDateTimeString();
        }

        return Carbon::parse($date)->toDateTimeString();
    }
}
