<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Order;
use App\Models\OrderLineItem;
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
        $seller = User::factory()->create(['printify_shop_id' => $shop->id]);
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
            ->assertJsonPath('data.data.0.seller.printify_shop_id', $shop->id)
            ->assertJsonPath('data.data.0.seller.printify_shop.id', $shop->id)
            ->assertJsonPath('data.data.0.seller.printify_shop.title', 'Alice Alice TT');
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
}
