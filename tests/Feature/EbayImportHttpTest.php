<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EbayImportHttpTest extends TestCase
{
    use DatabaseMigrations;

    private function actingImporter(): User
    {
        $user = User::factory()->create();
        Permission::findOrCreate('orders.import', 'api');
        $user->givePermissionTo('orders.import');
        Sanctum::actingAs($user);

        return $user;
    }

    private function csvUpload(string $name, string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    public function test_json_import_succeeds_and_returns_envelope(): void
    {
        $this->actingImporter();

        $response = $this->postJson('/api/orders/import', [
            'orders' => [
                [
                    'ebay_order_id' => '13-14975-00010',
                    'ebay_created_at' => '2026-08-02 12:00:00',
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.success', 1)
            ->assertJsonPath('data.failed', 0);

        $this->assertDatabaseHas('orders', [
            'ebay_order_id' => '13-14975-00010',
            'ebay_order_number' => '13-14975-00010',
        ]);
    }

    public function test_json_import_counts_duplicate_as_failed(): void
    {
        $this->actingImporter();
        Order::create([
            'ebay_order_id' => '13-14975-00010',
            'ebay_order_number' => '13-14975-00010',
            'ebay_created_at' => now(),
        ]);

        $response = $this->postJson('/api/orders/import', [
            'orders' => [
                [
                    'ebay_order_id' => '13-14975-00010',
                    'ebay_created_at' => '2026-08-02 12:00:00',
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.success', 0)
            ->assertJsonPath('data.failed', 1);
        $this->assertNotEmpty($response->json('data.errors'));
        $this->assertSame(1, Order::count());
    }

    public function test_json_import_requires_orders_payload(): void
    {
        $this->actingImporter();

        $this->postJson('/api/orders/import', [])
            ->assertUnprocessable()
            ->assertJsonPath('status', 'error');
    }

    public function test_csv_import_success_creates_order_and_line_items(): void
    {
        $this->actingImporter();
        $csv = "Order Number,Sale Date,Transaction ID,Item Number,Item Title,Custom Label,Variation Details,Quantity,Sold For,Shipping And Handling,Total Price,Ship To Name,Ship To Phone,Ship To Address 1,Ship To Address 2,Ship To City,Ship To State,Ship To Zip,Ship To Country\n"
            ."13-14975-00010,Aug-02-26,T-1,123,Shirt,,[Size:M],1,\$10.00,\$0.00,\$10.00,Jane Doe,,1 Main St,,Austin,TX,78701,US\n"
            ."13-14975-00010,Aug-02-26,T-2,124,Cap,,[Size:One],2,\$5.00,\$0.00,\$10.00,Jane Doe,,1 Main St,,Austin,TX,78701,US\n";
        $file = $this->csvUpload('orders.csv', $csv);

        $response = $this->post('/api/orders/import-csv', ['file' => $file], [
            'Accept' => 'application/json',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.orders', 1);

        $order = Order::firstOrFail();
        $this->assertCount(2, $order->lineItems);
        $this->assertSame('Austin', $order->fulfillmentAddress->city);
    }

    public function test_csv_import_missing_header_returns_422_and_marks_batch_failed(): void
    {
        $this->actingImporter();
        $file = $this->csvUpload('orders.csv', "Order Number,Item Number\n13-14975-00010,123\n");

        $response = $this->post('/api/orders/import-csv', ['file' => $file], [
            'Accept' => 'application/json',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('status', 'error');
        $this->assertStringContainsString('CSV thiếu cột', (string) $response->json('message'));
        $this->assertDatabaseHas('order_import_batches', ['status' => 'failed']);
        $this->assertSame(0, Order::count());
    }

    public function test_csv_import_malformed_money_returns_422(): void
    {
        $this->actingImporter();
        $csv = "Order Number,Sale Date,Item Number,Quantity,Sold For,Shipping And Handling,Total Price,Ship To Name,Ship To Address 1,Ship To City,Ship To Zip,Ship To Country\n"
            ."13-14975-00010,Aug-02-26,123,1,not-money,\$0.00,\$10.00,Jane Doe,1 Main St,Austin,78701,US\n";
        $file = $this->csvUpload('orders.csv', $csv);

        $this->post('/api/orders/import-csv', ['file' => $file], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('status', 'error');
    }

    public function test_csv_import_invalid_date_returns_422_without_committing_orders(): void
    {
        $this->actingImporter();
        $csv = "Order Number,Sale Date,Item Number,Quantity,Sold For,Shipping And Handling,Total Price,Ship To Name,Ship To Address 1,Ship To City,Ship To Zip,Ship To Country\n"
            ."13-14975-00010,Aug-02-26,123,1,\$10.00,\$0.00,\$10.00,Jane Doe,1 Main St,Austin,78701,US\n"
            ."13-14975-00011,invalid,124,1,\$10.00,\$0.00,\$10.00,Jane Doe,1 Main St,Austin,78701,US\n";
        $file = $this->csvUpload('orders.csv', $csv);

        $this->post('/api/orders/import-csv', ['file' => $file], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('status', 'error');

        $this->assertSame(0, Order::count());
    }

    public function test_csv_import_requires_file(): void
    {
        $this->actingImporter();

        $this->postJson('/api/orders/import-csv')
            ->assertUnprocessable()
            ->assertJsonPath('status', 'error');
    }

    public function test_import_template_downloads_csv_with_required_headers(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->get('/api/orders/import/template');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $body = $response->streamedContent();
        foreach (['Order Number', 'Sale Date', 'Item Number', 'Quantity', 'Sold For', 'Ship To Country'] as $header) {
            $this->assertStringContainsString($header, $body);
        }
    }
}
