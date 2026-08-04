<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\OrderImportService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class EbayCsvImportTest extends TestCase
{
    use DatabaseMigrations;

    public function test_it_ignores_noise_rows_and_groups_csv_lines_into_one_order(): void
    {
        $csv = ",,,,,,,,,,,,,,,,,\nOrder Number,Sale Date,Transaction ID,Item Number,Item Title,Custom Label,Variation Details,Quantity,Sold For,Shipping And Handling,Total Price,Ship To Name,Ship To Phone,Ship To Address 1,Ship To Address 2,Ship To City,Ship To State,Ship To Zip,Ship To Country\n,,,,,,,,,,,,,,,,,,\n13-14975-00010,Aug-02-26,T-1,123,Shirt,,[Size:M],1,$10.00,$0.00,$10.00,Jane Doe,,1 Main St,,Austin,TX,78701,US\n13-14975-00010,Aug-02-26,T-2,124,Cap,,[Size:One],2,$5.00,$0.00,$10.00,Jane Doe,,1 Main St,,Austin,TX,78701,US\n";
        $file = UploadedFile::fake()->createWithContent('orders.csv', $csv);

        $result = app(OrderImportService::class)->importFromCsv($file, null);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, Order::count());
        $order = Order::firstOrFail();
        $this->assertCount(2, $order->lineItems);
        $this->assertSame('Austin', $order->fulfillmentAddress->city);
    }

    public function test_it_rejects_malformed_money_values(): void
    {
        $csv = "Order Number,Sale Date,Item Number,Quantity,Sold For,Shipping And Handling,Total Price,Ship To Name,Ship To Address 1,Ship To City,Ship To Zip,Ship To Country\n13-14975-00010,Aug-02-26,123,1,not-money,$0.00,$10.00,Jane Doe,1 Main St,Austin,78701,US\n";
        $file = UploadedFile::fake()->createWithContent('orders.csv', $csv);

        $this->expectException(\RuntimeException::class);
        app(OrderImportService::class)->importFromCsv($file, null);
    }

    public function test_missing_required_headers_marks_the_batch_as_failed(): void
    {
        $file = UploadedFile::fake()->createWithContent('orders.csv', "Order Number,Item Number\n13-14975-00010,123\n");

        try {
            app(OrderImportService::class)->importFromCsv($file, null);
            $this->fail('Expected missing CSV headers to fail the batch.');
        } catch (\RuntimeException) {
        }
        $this->assertDatabaseHas('order_import_batches', ['status' => 'failed']);
    }

    public function test_fallback_line_items_are_idempotent_when_transaction_id_is_absent(): void
    {
        $csv = "Order Number,Sale Date,Item Number,Item Title,Custom Label,Variation Details,Quantity,Sold For,Shipping And Handling,Total Price,Ship To Name,Ship To Address 1,Ship To City,Ship To Zip,Ship To Country\n13-14975-00010,Aug-02-26,123,Shirt,,[Size:M],2,$10.00,$0.00,$20.00,Jane Doe,1 Main St,Austin,78701,US\n";
        $service = app(OrderImportService::class);

        $service->importFromCsv(UploadedFile::fake()->createWithContent('first.csv', $csv), null);
        $service->importFromCsv(UploadedFile::fake()->createWithContent('second.csv', $csv), null);

        $order = Order::firstOrFail();
        $this->assertCount(1, $order->lineItems);
        $this->assertSame(2, $order->lineItems->first()->quantity);
    }

    public function test_invalid_date_prevents_any_order_from_being_committed(): void
    {
        $csv = "Order Number,Sale Date,Item Number,Quantity,Sold For,Shipping And Handling,Total Price,Ship To Name,Ship To Address 1,Ship To City,Ship To Zip,Ship To Country\n13-14975-00010,Aug-02-26,123,1,$10.00,$0.00,$10.00,Jane Doe,1 Main St,Austin,78701,US\n13-14975-00011,invalid,124,1,$10.00,$0.00,$10.00,Jane Doe,1 Main St,Austin,78701,US\n";
        try {
            app(OrderImportService::class)->importFromCsv(UploadedFile::fake()->createWithContent('orders.csv', $csv), null);
            $this->fail('Expected invalid date to fail the import.');
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, Order::count());
    }

    public function test_reimport_with_transaction_identity_keeps_a_single_line_item(): void
    {
        $csv = "Order Number,Sale Date,Transaction ID,Item Number,Quantity,Sold For,Shipping And Handling,Total Price,Ship To Name,Ship To Address 1,Ship To City,Ship To Zip,Ship To Country\n13-14975-00010,Aug-02-26,T-1,123,1,$10.00,$0.00,$10.00,Jane Doe,1 Main St,Austin,78701,US\n";
        $service = app(OrderImportService::class);
        $service->importFromCsv(UploadedFile::fake()->createWithContent('first.csv', $csv), null);
        $service->importFromCsv(UploadedFile::fake()->createWithContent('second.csv', $csv), null);

        $this->assertCount(1, Order::firstOrFail()->lineItems);
    }
}
