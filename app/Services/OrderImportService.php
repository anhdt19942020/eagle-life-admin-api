<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderImportBatch;
use App\Models\OrderImportBatchItem;
use App\Models\OrderLineItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;
use RuntimeException;
use Symfony\Component\Intl\Countries;

class OrderImportService
{
    public const REQUIRED_CSV_HEADERS = [
        'Order Number',
        'Sale Date',
        'Item Number',
        'Quantity',
        'Sold For',
        'Shipping And Handling',
        'Total Price',
        'Ship To Name',
        'Ship To Address 1',
        'Ship To City',
        'Ship To Zip',
        'Ship To Country',
    ];

    public const OPTIONAL_CSV_HEADERS = [
        'Transaction ID',
        'Item Title',
        'Custom Label',
        'Variation Details',
        'Ship To Phone',
        'Ship To Address 2',
        'Ship To State',
    ];

    public function importFromArray(array $orders): array
    {
        $result = ['total' => count($orders), 'success' => 0, 'failed' => 0, 'errors' => []];
        $codes = collect($orders)->flatMap(fn (array $item) => [$item['buyer_code'] ?? null, $item['seller_code'] ?? null])->filter()->unique();
        $users = User::whereIn('employee_code', $codes)->pluck('id', 'employee_code');

        foreach ($orders as $index => $item) {
            $number = trim((string) $item['ebay_order_id']);
            if ($number === '' || Order::where('ebay_order_id', $number)->exists()) {
                $result['failed']++;
                $result['errors'][] = 'Mục #'.($index + 2).' không hợp lệ hoặc đã tồn tại';
                continue;
            }
            Order::create([
                'ebay_order_id' => $number,
                'ebay_order_number' => $number,
                'buyer_id' => $users[$item['buyer_code'] ?? ''] ?? null,
                'seller_id' => $users[$item['seller_code'] ?? ''] ?? null,
                'ebay_created_at' => $this->parseDate($item['ebay_created_at']),
            ]);
            $result['success']++;
        }

        return $result;
    }

    public function importFromCsv(UploadedFile $file, ?int $userId): array
    {
        $batch = OrderImportBatch::create([
            'created_by' => $userId,
            'source_filename' => basename($file->getClientOriginalName()),
            'status' => 'processing',
        ]);

        try {
            $records = iterator_to_array(Reader::createFromPath($file->getRealPath())->getRecords());
            $headerIndex = collect($records)->search(fn (array $row) => in_array('Order Number', $row, true));
            if ($headerIndex === false) {
                throw new RuntimeException('CSV thiếu hàng tiêu đề Order Number.');
            }

            $headers = array_map('trim', $records[$headerIndex]);
            foreach (self::REQUIRED_CSV_HEADERS as $required) {
                if (! in_array($required, $headers, true)) {
                    throw new RuntimeException("CSV thiếu cột {$required}.");
                }
            }

            $candidates = collect(array_slice($records, $headerIndex + 1))->values()->map(function (array $row, int $index) use ($headers, $headerIndex): array {
                if (count($row) > count($headers)) {
                    $row = array_slice($row, 0, count($headers));
                }

                return array_combine($headers, array_pad($row, count($headers), null)) + ['_row' => $headerIndex + $index + 2];
            })->filter(fn (array $row) => $this->isCandidateCsvRow($row));

            $rows = $candidates
                ->groupBy(fn (array $row) => trim((string) $row['Order Number']))
                ->flatMap(fn ($orderRows, string $number) => $this->normalizeEbayOrderRows($orderRows->values()->all(), $number))
                ->values();

            if ($rows->isEmpty()) {
                throw new RuntimeException('CSV không có dòng đơn hàng hợp lệ.');
            }

            foreach ($rows as $row) {
                $this->validateCsvRow($row);
            }

            $summary = [
                'batch_id' => $batch->id,
                'rows' => $rows->count(),
                'orders' => 0,
                'created' => 0,
                'updated' => 0,
                'failed' => 0,
                'errors' => [],
            ];

            foreach ($rows->groupBy(fn (array $row) => trim((string) $row['Order Number'])) as $number => $orderRows) {
                $this->persistCsvOrder($batch, $number, $orderRows->all(), $summary, $userId);
            }

            $batch->update([
                'status' => 'completed',
                'row_count' => $summary['rows'],
                'order_count' => $summary['orders'],
                'created_count' => $summary['created'],
                'updated_count' => $summary['updated'],
                'failed_count' => 0,
            ]);

            return $summary;
        } catch (\Throwable $exception) {
            $batch->update(['status' => 'failed']);
            throw $exception;
        }
    }

    private function persistCsvOrder(OrderImportBatch $batch, string $number, array $rows, array &$summary, ?int $userId): void
    {
        DB::transaction(function () use ($batch, $number, $rows, &$summary, $userId): void {
                $first = $rows[0];
                $existing = Order::withTrashed()->where('ebay_order_number', $number)->first();
                $created = $existing === null;

                if ($existing !== null && $existing->trashed()) {
                    $existing->restore();
                    $existing->forceFill(['deleted_by' => null])->save();
                }

                Order::upsert([['ebay_order_id' => $number, 'ebay_order_number' => $number, 'ebay_created_at' => $this->parseDate($first['Sale Date'] ?? null) ?? now(), 'created_at' => now(), 'updated_at' => now()]], ['ebay_order_number'], ['updated_at']);
                $order = Order::where('ebay_order_number', $number)->firstOrFail();
                $before = $created ? [] : $order->only(['buyer_id', 'seller_id', 'ebay_created_at']);
                $order->fill([
                    'ebay_order_id' => $order->ebay_order_id ?: $number,
                    'ebay_export_rows' => array_map(fn (array $row) => $this->sanitizeCsvPayload($row), $rows),
                    'ebay_buyer_username' => $this->nullableTrim($first['Buyer Username'] ?? null),
                    'ebay_buyer_name' => $this->nullableTrim($first['Buyer Name'] ?? null),
                    'ebay_buyer_email' => $this->nullableTrim($first['Buyer Email'] ?? null),
                ]);
                // Attribute to importer on create, or fill null seller_id on re-import — never steal an existing assignment.
                if ($userId !== null && ($created || $order->seller_id === null)) {
                    $order->seller_id = $userId;
                }
                $order->save();
                $order->fulfillmentAddress()->updateOrCreate([], $this->address($first));
                foreach (collect($rows)->groupBy(fn (array $row) => $this->lineItemKey($row)) as $lineRows) {
                    $this->upsertLineItem($order->id, $lineRows->first(), $lineRows->sum(fn (array $row) => (int) $row['Quantity']));
                }
                OrderImportBatchItem::create(['order_import_batch_id' => $batch->id, 'order_id' => $order->id, 'ebay_order_number' => $number, 'source_row' => $first['_row'], 'outcome' => $created ? 'created' : 'updated', 'was_created' => $created, 'before_values' => $before]);
                $summary['orders']++; $summary[$created ? 'created' : 'updated']++;
        });
    }

    private function sanitizeCsvPayload(array $row): array
    {
        unset($row['_row']);

        return $row;
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Keep order-shaped rows: full lines, address-only summary shells, or item
     * continuation lines (empty Ship To). Footer / noise still rejected.
     */
    private function isCandidateCsvRow(array $row): bool
    {
        $orderNumber = trim((string) ($row['Order Number'] ?? ''));
        // eBay sold export: 13-14975-00010 — skip footer like "record(s) downloaded"
        if (! preg_match('/^\d{2}-\d{5}-\d{5}$/', $orderNumber)) {
            return false;
        }

        if (trim((string) ($row['Sale Date'] ?? '')) === '') {
            return false;
        }

        $hasItem = trim((string) ($row['Item Number'] ?? '')) !== '';
        $hasShipTo = trim((string) ($row['Ship To Name'] ?? '')) !== '';

        return $hasItem || $hasShipTo;
    }

    /**
     * eBay multi-item exports put Ship To on the first row (sometimes without
     * Item Number) and leave Ship To blank on continuation item rows.
     */
    private function normalizeEbayOrderRows(array $rows, string $number): array
    {
        $carryFields = [
            'Ship To Name', 'Ship To Phone', 'Ship To Address 1', 'Ship To Address 2',
            'Ship To City', 'Ship To State', 'Ship To Zip', 'Ship To Country',
            'Buyer Username', 'Buyer Name', 'Buyer Email',
        ];

        $template = [];
        foreach ($rows as $row) {
            foreach ($carryFields as $field) {
                if (array_key_exists($field, $template)) {
                    continue;
                }
                if (trim((string) ($row[$field] ?? '')) !== '') {
                    $template[$field] = $row[$field];
                }
            }
        }

        $lineRows = [];
        foreach ($rows as $row) {
            if (trim((string) ($row['Item Number'] ?? '')) === '') {
                continue;
            }

            foreach ($template as $field => $value) {
                if (trim((string) ($row[$field] ?? '')) === '') {
                    $row[$field] = $value;
                }
            }

            foreach (['Shipping And Handling', 'Total Price'] as $moneyField) {
                if (trim((string) ($row[$moneyField] ?? '')) !== '') {
                    continue;
                }
                $row[$moneyField] = $moneyField === 'Total Price'
                    ? (trim((string) ($row['Sold For'] ?? '')) !== '' ? $row['Sold For'] : '$0.00')
                    : '$0.00';
            }

            $lineRows[] = $row;
        }

        if ($lineRows === []) {
            throw new RuntimeException("Order {$number}: CSV có Order Number nhưng thiếu dòng Item Number.");
        }

        foreach (['Ship To Name', 'Ship To Address 1', 'Ship To City', 'Ship To Zip', 'Ship To Country'] as $field) {
            if (trim((string) ($lineRows[0][$field] ?? '')) === '') {
                throw new RuntimeException("Order {$number}: missing {$field} after normalizing eBay multi-item rows.");
            }
        }

        return $lineRows;
    }

    private function address(array $row): array
    {
        $name = preg_split('/\s+/', trim((string) $row['Ship To Name']), 2);
        $country = trim((string) $row['Ship To Country']);

        return [
            'first_name' => $name[0] ?: 'Customer',
            'last_name' => $name[1] ?? null,
            'email' => $this->nullableTrim($row['Buyer Email'] ?? null),
            'phone' => $row['Ship To Phone'] ?? null,
            'address_line1' => trim((string) $row['Ship To Address 1']),
            'address_line2' => $row['Ship To Address 2'] ?? null,
            'city' => trim((string) $row['Ship To City']),
            'region' => $row['Ship To State'] ?? null,
            'postal_code' => trim((string) $row['Ship To Zip']),
            'country_code' => $this->countryCode($country),
            'country' => $country,
        ];
    }

    private function countryCode(string $value): string
    {
        $trimmed = trim($value);
        $upper = mb_strtoupper($trimmed, 'UTF-8');

        if (preg_match('/^[A-Z]{2}$/', $upper) === 1 && Countries::exists($upper)) {
            return $upper;
        }

        $aliases = [
            'UNITED STATES OF AMERICA' => 'US',
            'USA' => 'US',
            'GREAT BRITAIN' => 'GB',
            'ENGLAND' => 'GB',
            'CZECH REPUBLIC' => 'CZ',
            'SOUTH KOREA' => 'KR',
            'KOREA, SOUTH' => 'KR',
            'VIET NAM' => 'VN',
        ];

        if (isset($aliases[$upper])) {
            return $aliases[$upper];
        }

        $officialNames = array_map(
            static fn (string $name): string => mb_strtoupper($name, 'UTF-8'),
            Countries::getNames('en')
        );
        $code = array_search($upper, $officialNames, true);

        if ($code === false) {
            throw new RuntimeException("Unsupported Ship To Country: {$trimmed}");
        }

        return $code;
    }

    private function validateCsvRow(array $row): void
    {
        foreach (['Order Number', 'Sale Date', 'Item Number', 'Quantity', 'Ship To Name', 'Ship To Address 1', 'Ship To City', 'Ship To Zip', 'Ship To Country'] as $field) {
            if (trim((string) ($row[$field] ?? '')) === '') {
                throw new RuntimeException("CSV row {$row['_row']} is missing {$field}.");
            }
        }

        if (! Carbon::hasFormat(trim((string) $row['Sale Date']), 'M-d-y')) {
            throw new RuntimeException("CSV row {$row['_row']} has an invalid Sale Date.");
        }

        if (! ctype_digit(trim((string) $row['Quantity'])) || (int) $row['Quantity'] < 1) {
            throw new RuntimeException("CSV row {$row['_row']} has an invalid quantity.");
        }

        foreach (['Sold For', 'Total Price', 'Shipping And Handling'] as $field) {
            if (! preg_match('/^\$?\s*\d+(?:,\d{3})*(?:\.\d{2})?$/', trim((string) $row[$field]))) {
                throw new RuntimeException("CSV row {$row['_row']} has an invalid {$field}.");
            }
        }

        try {
            $this->countryCode(trim((string) $row['Ship To Country']));
        } catch (RuntimeException $exception) {
            $country = trim((string) $row['Ship To Country']);

            throw new RuntimeException("CSV row {$row['_row']} has an unsupported Ship To Country: {$country}.", 0, $exception);
        }
    }
    private function lineItemKey(array $row): string { $transaction = trim((string) ($row['Transaction ID'] ?? '')); return $transaction === '' ? 'fallback:'.$this->fallbackIdentity($row) : 'transaction:'.$transaction; }
    private function fallbackIdentity(array $row): string { return hash('sha256', implode('|', [trim((string) ($row['Item Number'] ?? '')), trim((string) ($row['Custom Label'] ?? '')), trim((string) ($row['Variation Details'] ?? '')), $this->money($row['Sold For'] ?? null), 'USD'])); }
    private function upsertLineItem(int $orderId, array $row, int $quantity): void
    {
        $transaction = trim((string) ($row['Transaction ID'] ?? ''));
        $price = $this->money($row['Sold For'] ?? null);
        $fallback = $transaction === '' ? $this->fallbackIdentity($row) : null;
        $identity = $transaction === '' ? ['fallback_identity' => $fallback] : ['transaction_id' => $transaction];
        $itemNumber = trim((string) ($row['Item Number'] ?? ''));
        // Blank Custom Label stays null — Printify resolve uses shop.default_sku at preview/create.
        $customLabel = trim((string) ($row['Custom Label'] ?? ''));

        $item = OrderLineItem::firstOrNew(['order_id' => $orderId] + $identity);
        $item->fill([
            'transaction_id' => $transaction ?: null,
            'fallback_identity' => $fallback,
            'item_number' => $itemNumber !== '' ? $itemNumber : ($row['Item Number'] ?? null),
            'title' => $row['Item Title'] ?? null,
            'custom_label' => $customLabel !== '' ? $customLabel : null,
            'variation' => $row['Variation Details'] ?? null,
            'quantity' => $quantity,
            'unit_price' => $price,
            'shipping_amount' => $this->money($row['Shipping And Handling'] ?? null),
            'total_amount' => $this->money($row['Total Price'] ?? null),
            'currency' => 'USD',
            'ebay_raw' => $this->sanitizeCsvPayload($row),
        ])->save();
    }
    private function money(?string $value): ?float { $value = trim((string) $value); return $value === '' ? null : (float) str_replace(['$', ','], '', $value); }
    private function parseDate(?string $date): ?string
    {
        if (!$date) return null;
        $value = trim($date);
        foreach (['M-d-y', 'd/m/Y', 'Y-m-d H:i:s', DATE_ATOM, 'Y-m-d'] as $format) {
            try { return Carbon::createFromFormat($format, $value)->toDateTimeString(); } catch (\Throwable) { continue; }
        }
        return Carbon::parse($value)->toDateTimeString();
    }
}
