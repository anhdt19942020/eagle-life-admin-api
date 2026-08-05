<?php

// db-refresh-allow: isolated sqlite DatabaseMigrations

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PrintifyOrder;
use App\Models\PrintifyProduct;
use App\Models\PrintifyProductVariant;
use App\Models\PrintifyShop;
use App\Models\User;
use App\Services\OrderImportService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PrintifyOrderCreateTest extends TestCase
{
    use DatabaseMigrations;

    private function readyShop(): PrintifyShop
    {
        return PrintifyShop::create([
            'printify_shop_id' => 101,
            'title' => 'Primary',
            'is_active' => true,
            'default_sku' => 'TEST-DEFAULT-SKU',
            'orders_sync_state' => 'complete',
            'manual_approval_confirmed_at' => now(),
        ]);
    }

    private function actingCreator(): User
    {
        $user = User::factory()->create();
        Permission::findOrCreate('printify.order.create', 'api');
        $user->givePermissionTo('printify.order.create');
        Sanctum::actingAs($user);

        return $user;
    }

    private function importOrderWithSku(string $sku): Order
    {
        $csv = "Order Number,Sale Date,Transaction ID,Item Number,Item Title,Custom Label,Variation Details,Quantity,Sold For,Shipping And Handling,Total Price,Buyer Username,Buyer Name,Buyer Email,Ship To Name,Ship To Phone,Ship To Address 1,Ship To Address 2,Ship To City,Ship To State,Ship To Zip,Ship To Country\n"
            ."13-14975-00010,Aug-02-26,T-1,123,Shirt,{$sku},[Size:M],1,\$10.00,\$0.00,\$10.00,harharrlind,Lindsey Harris,buyer@members.ebay.com,Lindsey Harris,+1 479-692-3507,4168 SR 326,,Russellville,AR,72802-1427,US\n";

        app(OrderImportService::class)->importFromCsv(
            UploadedFile::fake()->createWithContent('orders.csv', $csv),
            null
        );

        return Order::with(['lineItems', 'fulfillmentAddress'])->firstOrFail();
    }

    private function seedMappedVariant(PrintifyShop $shop, string $sku = 'SKU-M'): string
    {
        $remoteProductId = '5bfd0b66a342bcc9b5563216';
        $product = PrintifyProduct::create([
            'printify_shop_id' => $shop->id,
            'printify_product_id' => $remoteProductId,
            'title' => 'Tee',
        ]);
        PrintifyProductVariant::create([
            'printify_product_id' => $product->id,
            'printify_variant_id' => 9991,
            'sku' => $sku,
            'title' => 'M',
            'is_enabled' => true,
        ]);

        return $remoteProductId;
    }

    private function configurePrintifyHttp(): void
    {
        config()->set('services.printify.token', 'test-pat');
        config()->set('services.printify.base_url', 'https://printify.test/v1');
        config()->set('services.printify.retry_times', 0);
        config()->set('services.printify.retry_sleep_ms', 0);
    }

    public function test_create_posts_to_printify_and_persists_local_order(): void
    {
        $this->actingCreator();
        $this->configurePrintifyHttp();
        $shop = $this->readyShop();
        $remoteProductId = $this->seedMappedVariant($shop);
        $order = $this->importOrderWithSku('SKU-M');

        Http::fake([
            'printify.test/v1/shops/101/orders.json' => Http::response([
                'id' => 'pog-1',
                'status' => 'pending',
            ], 200),
        ]);

        $this->postJson("/api/orders/{$order->id}/printify-create", ['shop_id' => $shop->id])
            ->assertOk()
            ->assertJsonPath('data.created', true)
            ->assertJsonPath('data.printify_order.printify_order_id', 'pog-1')
            ->assertJsonPath('data.printify_order.ebay_order_number', '13-14975-00010')
            ->assertJsonPath('data.remote.id', 'pog-1');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://printify.test/v1/shops/101/orders.json'
            && $request['external_id'] === '13-14975-00010'
            && ($request['line_items'][0]['product_id'] ?? null) === $remoteProductId
            && ($request['line_items'][0]['variant_id'] ?? null) === 9991);

        $this->assertDatabaseHas('printify_orders', [
            'order_id' => $order->id,
            'printify_order_id' => 'pog-1',
            'ebay_order_number' => '13-14975-00010',
            'intent_state' => 'created',
        ]);
        $this->assertSame('pog-1', $order->fresh()->printify_order_id);
    }

    public function test_create_is_idempotent_when_ebay_number_already_linked(): void
    {
        $this->actingCreator();
        $this->configurePrintifyHttp();
        $shop = $this->readyShop();
        $this->seedMappedVariant($shop);
        $order = $this->importOrderWithSku('SKU-M');

        PrintifyOrder::create([
            'order_id' => $order->id,
            'printify_shop_id' => $shop->id,
            'printify_order_id' => 'pog-existing',
            'ebay_order_number' => '13-14975-00010',
            'status' => 'pending',
            'intent_state' => 'created',
            'attempt_key' => 'create:'.$shop->id.':13-14975-00010',
        ]);

        Http::fake();

        $this->postJson("/api/orders/{$order->id}/printify-create", ['shop_id' => $shop->id])
            ->assertOk()
            ->assertJsonPath('data.created', false)
            ->assertJsonPath('data.printify_order.printify_order_id', 'pog-existing');

        Http::assertNothingSent();
        $this->assertSame(1, PrintifyOrder::count());
    }

    public function test_create_returns_422_when_payload_not_ready(): void
    {
        $this->actingCreator();
        $this->configurePrintifyHttp();
        $shop = $this->readyShop();
        $order = $this->importOrderWithSku('UNKNOWN-SKU');

        Http::fake();

        $this->postJson("/api/orders/{$order->id}/printify-create", ['shop_id' => $shop->id])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        Http::assertNothingSent();
        $this->assertSame(0, PrintifyOrder::count());
    }

    public function test_create_requires_printify_order_create_permission(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $shop = $this->readyShop();
        $order = $this->importOrderWithSku('SKU-M');

        $this->postJson("/api/orders/{$order->id}/printify-create", ['shop_id' => $shop->id])
            ->assertForbidden();
    }
}
