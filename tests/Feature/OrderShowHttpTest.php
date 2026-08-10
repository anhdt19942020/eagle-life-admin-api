<?php

// db-refresh-allow: matches existing Feature suite; phpunit uses isolated sqlite :memory: via DatabaseMigrations

namespace Tests\Feature;

use App\Models\User;
use App\Models\Order;
use App\Models\OrderLineItem;
use App\Models\PrintifyOrder;
use App\Services\OrderImportService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\InteractsWithPrintifyAccounts;
use Tests\TestCase;

class OrderShowHttpTest extends TestCase
{
    use DatabaseMigrations;
    use InteractsWithPrintifyAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_order_index_includes_line_item_titles_for_the_orders_table(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        Sanctum::actingAs($user);

        $order = Order::create([
            'ebay_order_id' => '13-14975-00011',
            'ebay_order_number' => '13-14975-00011',
            'ebay_created_at' => '2026-08-02 12:00:00',
        ]);
        OrderLineItem::create([
            'order_id' => $order->id,
            'item_number' => '123',
            'title' => 'Long product title for the orders list',
            'quantity' => 1,
        ]);

        $this->getJson('/api/orders')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.data.0.line_items.0.title', 'Long product title for the orders list');
    }

    public function test_order_index_includes_printify_order_status_for_the_orders_table(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        Sanctum::actingAs($user);

        $account = $this->makePrintifyAccount();
        $shop = $this->makePrintifyShop($account);
        $order = Order::create([
            'ebay_order_id' => '13-14975-00012',
            'ebay_order_number' => '13-14975-00012',
            'ebay_created_at' => '2026-08-02 12:00:00',
            'printify_order_id' => 'printify-123',
        ]);
        PrintifyOrder::create([
            'order_id' => $order->id,
            'printify_shop_id' => $shop->id,
            'printify_order_id' => 'printify-123',
            'status' => 'in-production',
            'intent_state' => 'synced',
            'synced_at' => '2026-08-02 12:05:00',
        ]);

        $this->getJson('/api/orders')
            ->assertOk()
            ->assertJsonPath('data.data.0.printify_order.status', 'in-production')
            ->assertJsonPath('data.data.0.printify_order.intent_state', 'synced');
    }

    public function test_order_index_includes_seller_printify_shop_for_admin_bulk_draft_ui(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);

        $account = $this->makePrintifyAccount();
        $shop = $this->makePrintifyShop($account, [
            'printify_shop_id' => 422,
            'title' => 'Alice Alice TT',
        ]);
        $seller = $this->makeSellerForShop($shop);
        $seller->assignRole('seller');

        Order::create([
            'ebay_order_id' => '23-14960-52371',
            'ebay_order_number' => '23-14960-52371',
            'ebay_created_at' => '2026-08-02 12:00:00',
            'seller_id' => $seller->id,
        ]);

        $this->getJson('/api/orders')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.data.0.seller.id', $seller->id)
            ->assertJsonPath('data.data.0.seller.printify_shops.0.id', $shop->id)
            ->assertJsonPath('data.data.0.seller.printify_shops.0.title', 'Alice Alice TT');
    }

    public function test_order_index_includes_seller_stats_ignoring_seller_id_filter(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);

        $sellerA = User::factory()->create(['name' => 'Seller A']);
        $sellerA->assignRole('seller');
        $sellerB = User::factory()->create(['name' => 'Seller B']);
        $sellerB->assignRole('seller');

        Order::create([
            'ebay_order_id' => 'ORD-A1',
            'ebay_order_number' => 'ORD-A1',
            'seller_id' => $sellerA->id,
            'ebay_created_at' => '2026-08-02 12:00:00',
        ]);
        Order::create([
            'ebay_order_id' => 'ORD-A2',
            'ebay_order_number' => 'ORD-A2',
            'seller_id' => $sellerA->id,
            'ebay_created_at' => '2026-08-02 13:00:00',
        ]);
        Order::create([
            'ebay_order_id' => 'ORD-B1',
            'ebay_order_number' => 'ORD-B1',
            'seller_id' => $sellerB->id,
            'ebay_created_at' => '2026-08-02 14:00:00',
        ]);
        Order::create([
            'ebay_order_id' => 'ORD-NULL',
            'ebay_order_number' => 'ORD-NULL',
            'seller_id' => null,
            'ebay_created_at' => '2026-08-02 15:00:00',
        ]);

        $response = $this->getJson('/api/orders?seller_id='.$sellerA->id)->assertOk();

        $listIds = collect($response->json('data.data'))->pluck('id');
        $this->assertCount(2, $listIds);

        $stats = collect($response->json('data.seller_stats'));
        $this->assertSame(2, $stats->firstWhere('seller_id', $sellerA->id)['orders_count']);
        $this->assertSame(1, $stats->firstWhere('seller_id', $sellerB->id)['orders_count']);
        $this->assertSame(1, $stats->firstWhere('seller_id', null)['orders_count']);
        $this->assertSame('Seller A', $stats->firstWhere('seller_id', $sellerA->id)['seller']['name']);
    }

    public function test_order_show_includes_fulfillment_address_line_items_and_export_rows(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        Permission::findOrCreate('orders.import', 'api');
        $user->givePermissionTo('orders.import');
        Sanctum::actingAs($user);

        $csv = "Order Number,Sale Date,Transaction ID,Item Number,Item Title,Custom Label,Variation Details,Quantity,Sold For,Shipping And Handling,Total Price,Buyer Username,Buyer Name,Buyer Email,Ship To Name,Ship To Phone,Ship To Address 1,Ship To Address 2,Ship To City,Ship To State,Ship To Zip,Ship To Country\n"
            ."13-14975-00010,Aug-02-26,T-1,123,Shirt,,[Size:M],1,\$10.00,\$0.00,\$10.00,harharrlind,Lindsey Harris,buyer@members.ebay.com,Lindsey Harris,+1 479-692-3507,4168 SR 326,,Russellville,AR,72802-1427,US\n";

        app(OrderImportService::class)->importFromCsv(
            UploadedFile::fake()->createWithContent('orders.csv', $csv),
            $user->id
        );

        $orderId = \App\Models\Order::firstOrFail()->id;

        $this->getJson("/api/orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.ebay_order_number', '13-14975-00010')
            ->assertJsonPath('data.ebay_buyer_email', 'buyer@members.ebay.com')
            ->assertJsonPath('data.ebay_export_rows.0.Buyer Email', 'buyer@members.ebay.com')
            ->assertJsonPath('data.fulfillment_address.city', 'Russellville')
            ->assertJsonPath('data.fulfillment_address.email', 'buyer@members.ebay.com')
            ->assertJsonPath('data.fulfillment_address.country_code', 'US')
            ->assertJsonPath('data.line_items.0.item_number', '123')
            ->assertJsonPath('data.line_items.0.ebay_raw.Item Title', 'Shirt');
    }

    public function test_order_index_exposes_ebay_export_rows_for_my_item_note_and_country_columns(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        Permission::findOrCreate('orders.import', 'api');
        $user->givePermissionTo('orders.import');
        Sanctum::actingAs($user);

        $csv = "Order Number,Sale Date,Transaction ID,Item Number,Item Title,Custom Label,Variation Details,Quantity,Sold For,Shipping And Handling,Total Price,Buyer Username,Buyer Name,Buyer Email,Buyer Country,My Item Note,Ship To Name,Ship To Phone,Ship To Address 1,Ship To Address 2,Ship To City,Ship To State,Ship To Zip,Ship To Country\n"
            ."23-99999-00001,Aug-02-26,T-2,456,Mug,,[Color:Blue],1,\$8.00,\$0.00,\$8.00,buyeruser,Jane Buyer,jane@members.ebay.com,US,Handle with care,Jane Buyer,+1 555-0100,1 Main St,,Anytown,CA,90001,US\n";

        app(OrderImportService::class)->importFromCsv(
            UploadedFile::fake()->createWithContent('orders.csv', $csv),
            $user->id
        );

        $this->getJson('/api/orders')
            ->assertOk()
            ->assertJsonPath('data.data.0.ebay_export_rows.0.My Item Note', 'Handle with care')
            ->assertJsonPath('data.data.0.ebay_export_rows.0.Buyer Country', 'US');
    }
}
