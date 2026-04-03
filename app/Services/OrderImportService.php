<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use League\Csv\Reader;
use League\Csv\Statement;

class OrderImportService
{
    const CHUNK_SIZE = 100;

    // CSV header: ebay_order_id bắt buộc
    const REQUIRED_HEADERS = ['ebay_order_id', 'ebay_created_at'];

    public function import(string $filePath): array
    {
        $csv = Reader::createFromPath($filePath, 'r');
        $csv->setHeaderOffset(0);

        $headers = array_map('trim', $csv->getHeader());
        $missingHeaders = array_diff(self::REQUIRED_HEADERS, $headers);
        if (!empty($missingHeaders)) {
            throw new \InvalidArgumentException(
                'File CSV thiếu cột bắt buộc: ' . implode(', ', $missingHeaders)
            );
        }

        $result = [
            'total'   => 0,
            'success' => 0,
            'failed'  => 0,
            'errors'  => [],
        ];

        $records = Statement::create()->process($csv);
        $rowNumber = 2;
        $chunk = [];

        foreach ($records as $record) {
            $record = array_map('trim', $record);
            $chunk[] = ['row' => $rowNumber, 'data' => $record];
            $result['total']++;
            $rowNumber++;

            if (count($chunk) >= self::CHUNK_SIZE) {
                $this->processChunk($chunk, $result);
                $chunk = [];
            }
        }

        if (!empty($chunk)) {
            $this->processChunk($chunk, $result);
        }

        return $result;
    }

    private function processChunk(array $chunk, array &$result): void
    {
        $toInsert = [];

        foreach ($chunk as $item) {
            $rowNumber = $item['row'];
            $record = $item['data'];

            if (empty($record['ebay_order_id'])) {
                $result['failed']++;
                $result['errors'][] = "Dòng {$rowNumber}: Thiếu mã đơn eBay (ebay_order_id)";
                continue;
            }

            if (empty($record['ebay_created_at'])) {
                $result['failed']++;
                $result['errors'][] = "Dòng {$rowNumber}: Thiếu thời gian tạo eBay (ebay_created_at)";
                continue;
            }

            // Kiểm tra trùng ebay_order_id
            if (Order::where('ebay_order_id', $record['ebay_order_id'])->exists()) {
                $result['failed']++;
                $result['errors'][] = "Dòng {$rowNumber}: Mã eBay '{$record['ebay_order_id']}' đã tồn tại";
                continue;
            }

            // Resolve buyer/seller từ employee_code
            $buyerId = null;
            if (!empty($record['buyer_code'])) {
                $buyer = User::where('employee_code', $record['buyer_code'])->first();
                $buyerId = $buyer?->id;
            }

            $sellerId = null;
            if (!empty($record['seller_code'])) {
                $seller = User::where('employee_code', $record['seller_code'])->first();
                $sellerId = $seller?->id;
            }

            $toInsert[] = [
                'ebay_order_id'      => $record['ebay_order_id'],
                'buyer_id'           => $buyerId,
                'seller_id'          => $sellerId,
                'ebay_created_at'    => $record['ebay_created_at'],
                'printify_created_at' => $record['printify_created_at'] ?? null,
                'printify_order_id'  => $record['printify_order_id'] ?? null,
                'created_at'         => now(),
                'updated_at'         => now(),
            ];
        }

        if (!empty($toInsert)) {
            Order::insert($toInsert);
            $result['success'] += count($toInsert);
        }
    }
}
