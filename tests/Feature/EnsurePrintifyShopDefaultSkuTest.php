<?php

// db-refresh-allow: isolated sqlite DatabaseMigrations

namespace Tests\Feature;

use App\Models\PrintifyProduct;
use App\Models\PrintifyProductVariant;
use App\Models\PrintifyShop;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\InteractsWithPrintifyAccounts;
use Tests\TestCase;

class EnsurePrintifyShopDefaultSkuTest extends TestCase
{
    use DatabaseMigrations;
    use InteractsWithPrintifyAccounts;

    public function test_ensure_sets_unique_enabled_sku_on_open_shop(): void
    {
        $account = $this->makePrintifyAccount();
        $shop = $this->makePrintifyShop($account, [
            'printify_shop_id' => 501,
            'title' => 'Need Default',
            'default_sku' => null,
            'orders_sync_state' => 'complete',
            'manual_approval_confirmed_at' => now(),
        ]);
        $product = PrintifyProduct::create([
            'printify_shop_id' => $shop->id,
            'printify_product_id' => 'prod-1',
            'title' => 'Placeholder',
        ]);
        PrintifyProductVariant::create([
            'printify_product_id' => $product->id,
            'printify_variant_id' => 1,
            'sku' => 'UNIQUE-DEF-1',
            'title' => 'S',
            'is_enabled' => true,
        ]);

        $this->artisan('printify:ensure-default-sku', ['--open-only' => true])
            ->assertSuccessful();

        $this->assertSame('UNIQUE-DEF-1', $shop->fresh()->default_sku);
    }

    public function test_ensure_dry_run_does_not_write(): void
    {
        $account = $this->makePrintifyAccount();
        $shop = $this->makePrintifyShop($account, [
            'printify_shop_id' => 502,
            'title' => 'Dry',
            'default_sku' => null,
            'orders_sync_state' => 'complete',
            'manual_approval_confirmed_at' => now(),
        ]);
        $product = PrintifyProduct::create([
            'printify_shop_id' => $shop->id,
            'printify_product_id' => 'prod-2',
            'title' => 'Placeholder',
        ]);
        PrintifyProductVariant::create([
            'printify_product_id' => $product->id,
            'printify_variant_id' => 2,
            'sku' => 'DRY-SKU',
            'title' => 'M',
            'is_enabled' => true,
        ]);

        $this->artisan('printify:ensure-default-sku', [
            '--open-only' => true,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertNull($shop->fresh()->default_sku);
    }

    public function test_ensure_skips_ambiguous_sku(): void
    {
        $account = $this->makePrintifyAccount('ambig@example.com', 'ambig-pat');
        $shop = $this->makePrintifyShop($account, [
            'printify_shop_id' => 503,
            'title' => 'Ambiguous',
            'default_sku' => null,
            'orders_sync_state' => 'complete',
            'manual_approval_confirmed_at' => now(),
        ]);
        $product = PrintifyProduct::create([
            'printify_shop_id' => $shop->id,
            'printify_product_id' => 'prod-3',
            'title' => 'Dup',
        ]);
        PrintifyProductVariant::create([
            'printify_product_id' => $product->id,
            'printify_variant_id' => 3,
            'sku' => 'DUP',
            'title' => 'A',
            'is_enabled' => true,
        ]);
        PrintifyProductVariant::create([
            'printify_product_id' => $product->id,
            'printify_variant_id' => 4,
            'sku' => 'DUP',
            'title' => 'B',
            'is_enabled' => true,
        ]);

        $this->artisan('printify:ensure-default-sku', ['--open-only' => true])
            ->assertSuccessful();

        $this->assertNull($shop->fresh()->default_sku);
    }
}
