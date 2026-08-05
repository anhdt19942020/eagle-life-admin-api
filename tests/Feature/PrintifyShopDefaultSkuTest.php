<?php

// db-refresh-allow: isolated sqlite DatabaseMigrations

namespace Tests\Feature;

use App\Models\PrintifyProduct;
use App\Models\PrintifyProductVariant;
use App\Models\PrintifyShop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PrintifyShopDefaultSkuTest extends TestCase
{
    use DatabaseMigrations;

    private function shopWithVariant(string $sku = 'SHOP-DEFAULT', int $variantCount = 1): PrintifyShop
    {
        $shop = PrintifyShop::create([
            'printify_shop_id' => 501,
            'title' => 'SKU Shop',
            'is_active' => true,
            'is_open' => true,
            'orders_sync_state' => 'complete',
            'manual_approval_confirmed_at' => now(),
        ]);

        $product = PrintifyProduct::create([
            'printify_shop_id' => $shop->id,
            'printify_product_id' => 'prod-default-sku',
            'title' => 'Placeholder',
        ]);

        for ($i = 0; $i < $variantCount; $i++) {
            PrintifyProductVariant::create([
                'printify_product_id' => $product->id,
                'printify_variant_id' => 7000 + $i,
                'sku' => $sku,
                'title' => 'V'.$i,
                'is_enabled' => true,
            ]);
        }

        return $shop;
    }

    private function actingConfirm(): User
    {
        $user = User::factory()->create();
        Permission::findOrCreate('printify.shop-readiness.confirm', 'api');
        $user->givePermissionTo('printify.shop-readiness.confirm');
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_patch_sets_default_sku_when_unique_enabled_variant_exists(): void
    {
        $shop = $this->shopWithVariant('UNIQUE-SKU');
        $this->actingConfirm();

        $this->patchJson("/api/printify/shops/{$shop->id}", ['default_sku' => 'UNIQUE-SKU'])
            ->assertOk()
            ->assertJsonPath('data.default_sku', 'UNIQUE-SKU');

        $this->assertSame('UNIQUE-SKU', $shop->fresh()->default_sku);
    }

    public function test_patch_clears_default_sku_with_null_or_blank(): void
    {
        $shop = $this->shopWithVariant();
        $shop->forceFill(['default_sku' => 'SHOP-DEFAULT'])->save();
        $this->actingConfirm();

        $this->patchJson("/api/printify/shops/{$shop->id}", ['default_sku' => ''])
            ->assertOk()
            ->assertJsonPath('data.default_sku', null);

        $this->assertNull($shop->fresh()->default_sku);
    }

    public function test_patch_rejects_missing_sku(): void
    {
        $shop = $this->shopWithVariant();
        $this->actingConfirm();

        $this->patchJson("/api/printify/shops/{$shop->id}", ['default_sku' => 'NO-SUCH'])
            ->assertStatus(422)
            ->assertJsonFragment(['Không có variant enabled với SKU [NO-SUCH] trên shop này. Sync products trước rồi thử lại.']);
    }

    public function test_patch_rejects_ambiguous_sku(): void
    {
        $shop = $this->shopWithVariant('DUP-SKU', 2);
        $this->actingConfirm();

        $this->patchJson("/api/printify/shops/{$shop->id}", ['default_sku' => 'DUP-SKU'])
            ->assertStatus(422)
            ->assertJsonFragment(['SKU [DUP-SKU] khớp 2 variants trên shop — chọn SKU unique hơn.']);
    }

    public function test_patch_requires_permission(): void
    {
        $shop = $this->shopWithVariant();
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson("/api/printify/shops/{$shop->id}", ['default_sku' => 'SHOP-DEFAULT'])
            ->assertForbidden();
    }
}
