<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use League\Csv\Reader;
use League\Csv\Statement;

class OrderImportService
{
    const CHUNK_SIZE = 100;

    // Expected CSV headers (case-insensitive)
    const REQUIRED_HEADERS = ['customer_name'];
    const OPTIONAL_HEADERS = ['customer_phone', 'customer_email', 'total_amount', 'status', 'sale_code', 'notes'];

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
            'total' => 0,
            'success' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        $records = Statement::create()->process($csv);
        $rowNumber = 2; // Row 1 is header
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

        // Process remaining records
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

            if (empty($record['customer_name'])) {
                $result['failed']++;
                $result['errors'][] = "Dòng {$rowNumber}: Thiếu tên khách hàng";
                continue;
            }

            $status = $record['status'] ?? 'pending';
            if (!in_array($status, ['pending', 'processing', 'completed', 'canceled'])) {
                $status = 'pending';
            }

            $saleId = null;
            if (!empty($record['sale_code'])) {
                $sale = User::where('employee_code', $record['sale_code'])->first();
                if ($sale) {
                    $saleId = $sale->id;
                }
            }

            $toInsert[] = [
                'order_code' => Order::generateCode(),
                'customer_name' => $record['customer_name'],
                'customer_phone' => $record['customer_phone'] ?? null,
                'customer_email' => $record['customer_email'] ?? null,
                'total_amount' => is_numeric($record['total_amount'] ?? null) ? $record['total_amount'] : 0,
                'status' => $status,
                'sale_id' => $saleId,
                'notes' => $record['notes'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (!empty($toInsert)) {
            Order::insert($toInsert);
            $result['success'] += count($toInsert);
        }
    }
}
