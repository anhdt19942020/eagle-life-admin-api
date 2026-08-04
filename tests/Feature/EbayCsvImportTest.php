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
            // Expected: missing headers must fail the batch; assert status below.
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
            // Expected: invalid date aborts before any order commit; assert count below.
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

    public function test_it_persists_full_csv_payload_and_buyer_fields(): void
    {
        $header = 'Order Number,Sale Date,Transaction ID,Item Number,Item Title,Custom Label,Variation Details,Quantity,Sold For,Shipping And Handling,Total Price,Buyer Username,Buyer Name,Buyer Email,Sold Via Promoted Listings,Ship To Name,Ship To Phone,Ship To Address 1,Ship To Address 2,Ship To City,Ship To State,Ship To Zip,Ship To Country';
        $row = '13-14975-00010,Aug-02-26,10085125720813,397424275164,Megadeth Shirt,,"[Size:M,Size Type:Regular]",1,$15.74,$1.99,$17.73,harharrlind,Lindsey Harris,buyer@members.ebay.com,Yes,Lindsey Harris,+1 479-692-3507,4168 SR 326,,Russellville,AR,72802-1427,US';
        $service = app(OrderImportService::class);

        $service->importFromCsv(UploadedFile::fake()->createWithContent('first.csv', "{$header}\n{$row}\n"), null);

        $order = Order::with('lineItems')->firstOrFail();
        $this->assertSame('harharrlind', $order->ebay_buyer_username);
        $this->assertSame('Lindsey Harris', $order->ebay_buyer_name);
        $this->assertSame('buyer@members.ebay.com', $order->ebay_buyer_email);
        $this->assertSame('buyer@members.ebay.com', $order->ebay_export_rows[0]['Buyer Email']);
        $this->assertSame('Yes', $order->ebay_export_rows[0]['Sold Via Promoted Listings']);
        $this->assertArrayNotHasKey('_row', $order->ebay_export_rows[0]);
        $this->assertSame('[Size:M,Size Type:Regular]', $order->lineItems->first()->ebay_raw['Variation Details']);
        $this->assertSame('397424275164', $order->lineItems->first()->ebay_raw['Item Number']);

        $updated = str_replace('buyer@members.ebay.com', 'updated@members.ebay.com', $row);
        $service->importFromCsv(UploadedFile::fake()->createWithContent('second.csv', "{$header}\n{$updated}\n"), null);

        $order->refresh()->load('lineItems');
        $this->assertSame(1, Order::count());
        $this->assertCount(1, $order->lineItems);
        $this->assertSame('updated@members.ebay.com', $order->ebay_buyer_email);
        $this->assertSame('updated@members.ebay.com', $order->ebay_export_rows[0]['Buyer Email']);
        $this->assertSame('updated@members.ebay.com', $order->lineItems->first()->ebay_raw['Buyer Email']);
    }
}
