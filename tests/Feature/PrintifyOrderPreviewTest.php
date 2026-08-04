<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PrintifyProduct;
use App\Models\PrintifyProductVariant;
use App\Models\PrintifyShop;
use App\Models\User;
use App\Services\OrderImportService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PrintifyOrderPreviewTest extends TestCase
{
    use DatabaseMigrations;

    private function readyShop(): PrintifyShop
    {
        return PrintifyShop::create([
            'printify_shop_id' => 101,
            'title' => 'Primary',
            'is_active' => true,
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

    public function test_preview_builds_payload_when_custom_label_matches_variant_sku(): void
    {
        $this->actingCreator();
        $shop = $this->readyShop();
        $product = PrintifyProduct::create([
            'printify_shop_id' => $shop->id,
            'printify_product_id' => 555,
            'title' => 'Tee',
        ]);
        PrintifyProductVariant::create([
            'printify_product_id' => $product->id,
            'printify_variant_id' => 9991,
            'sku' => 'SKU-M',
            'title' => 'M',
            'is_enabled' => true,
        ]);

        $order = $this->importOrderWithSku('SKU-M');

        $this->postJson("/api/orders/{$order->id}/printify-preview", ['shop_id' => $shop->id])
            ->assertOk()
            ->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.payload.external_id', '13-14975-00010')
            ->assertJsonPath('data.payload.address_to.email', 'buyer@members.ebay.com')
            ->assertJsonPath('data.payload.address_to.country', 'US')
            ->assertJsonPath('data.payload.line_items.0.product_id', 555)
            ->assertJsonPath('data.payload.line_items.0.variant_id', 9991)
            ->assertJsonPath('data.line_mappings.0.source', 'sku');
    }

    public function test_preview_reports_missing_sku_mapping_without_calling_printify(): void
    {
        $this->actingCreator();
        $shop = $this->readyShop();
        $order = $this->importOrderWithSku('UNKNOWN-SKU');

        $this->postJson("/api/orders/{$order->id}/printify-preview", ['shop_id' => $shop->id])
            ->assertOk()
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.payload', null)
            ->assertJsonFragment(['Line item '.$order->lineItems->first()->id.': no enabled Printify variant with SKU [UNKNOWN-SKU].']);
    }

    public function test_preview_accepts_manual_variant_mapping(): void
    {
        $this->actingCreator();
        $shop = $this->readyShop();
        $product = PrintifyProduct::create([
            'printify_shop_id' => $shop->id,
            'printify_product_id' => 555,
            'title' => 'Tee',
        ]);
        PrintifyProductVariant::create([
            'printify_product_id' => $product->id,
            'printify_variant_id' => 9991,
            'sku' => 'OTHER',
            'title' => 'M',
            'is_enabled' => true,
        ]);

        $order = $this->importOrderWithSku('');
        $lineId = $order->lineItems->first()->id;

        $this->postJson("/api/orders/{$order->id}/printify-preview", [
            'shop_id' => $shop->id,
            'line_mappings' => [
                ['line_item_id' => $lineId, 'variant_id' => 9991],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.line_mappings.0.source', 'manual')
            ->assertJsonPath('data.payload.line_items.0.variant_id', 9991);
    }

    public function test_preview_requires_printify_order_create_permission(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $shop = $this->readyShop();
        $order = $this->importOrderWithSku('SKU-M');

        $this->postJson("/api/orders/{$order->id}/printify-preview", ['shop_id' => $shop->id])
            ->assertForbidden();
    }
}
