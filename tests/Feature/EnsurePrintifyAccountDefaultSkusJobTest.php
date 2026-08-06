<?php

// db-refresh-allow: isolated sqlite DatabaseMigrations

namespace Tests\Feature;

use App\Jobs\EnsurePrintifyAccountDefaultSkusJob;
use App\Models\PrintifyProduct;
use App\Models\PrintifyProductVariant;
use App\Services\Printify\PrintifyDefaultSkuEnsurer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithPrintifyAccounts;
use Tests\TestCase;

class EnsurePrintifyAccountDefaultSkusJobTest extends TestCase
{
    use DatabaseMigrations;
    use InteractsWithPrintifyAccounts;

    public function test_job_seeds_one_product_and_sets_unique_default_sku(): void
    {
        $this->configurePrintifyHttpBase();
        $account = $this->makePrintifyAccount();
        $shop = $this->makePrintifyShop($account, [
            'printify_shop_id' => 101,
            'default_sku' => null,
        ]);

        Http::fake([
            'printify.test/v1/shops/101/products.json*' => Http::response([
                'data' => [
                    [
                        'id' => 'p1',
                        'title' => 'Seed Tee',
                        'variants' => [
                            ['id' => 1, 'sku' => 'SEED-SKU-1', 'title' => 'S', 'is_enabled' => true],
                        ],
                    ],
                    [
                        'id' => 'p2',
                        'title' => 'Should Not Sync',
                        'variants' => [
                            ['id' => 2, 'sku' => 'OTHER', 'title' => 'M', 'is_enabled' => true],
                        ],
                    ],
                ],
                'last_page' => 1,
            ]),
        ]);

        $job = new EnsurePrintifyAccountDefaultSkusJob($account->id);
        $job->handle(app(PrintifyDefaultSkuEnsurer::class));

        $this->assertSame('SEED-SKU-1', $shop->fresh()->default_sku);
        $this->assertSame(1, PrintifyProduct::count());
    }

    public function test_job_does_not_overwrite_existing_default_sku(): void
    {
        $this->configurePrintifyHttpBase();
        $account = $this->makePrintifyAccount();
        $shop = $this->makePrintifyShop($account, [
            'printify_shop_id' => 101,
            'default_sku' => 'KEEP-ME',
        ]);

        Http::fake([
            'printify.test/v1/shops/101/products.json*' => Http::response([
                'data' => [
                    [
                        'id' => 'p1',
                        'title' => 'Seed Tee',
                        'variants' => [
                            ['id' => 1, 'sku' => 'NEW-SKU', 'title' => 'S', 'is_enabled' => true],
                        ],
                    ],
                ],
                'last_page' => 1,
            ]),
        ]);

        $job = new EnsurePrintifyAccountDefaultSkusJob($account->id);
        $job->handle(app(PrintifyDefaultSkuEnsurer::class));

        $this->assertSame('KEEP-ME', $shop->fresh()->default_sku);
        $this->assertSame(0, PrintifyProduct::count());
        Http::assertNothingSent();
    }

    public function test_job_skips_ambiguous_local_skus_without_guessing(): void
    {
        $account = $this->makePrintifyAccount();
        $shop = $this->makePrintifyShop($account, [
            'printify_shop_id' => 101,
            'default_sku' => null,
        ]);
        $product = PrintifyProduct::create([
            'printify_shop_id' => $shop->id,
            'printify_product_id' => 'prod-dup',
            'title' => 'Dup',
        ]);
        PrintifyProductVariant::create([
            'printify_product_id' => $product->id,
            'printify_variant_id' => 1,
            'sku' => 'DUP',
            'title' => 'A',
            'is_enabled' => true,
        ]);
        PrintifyProductVariant::create([
            'printify_product_id' => $product->id,
            'printify_variant_id' => 2,
            'sku' => 'DUP',
            'title' => 'B',
            'is_enabled' => true,
        ]);

        $this->configurePrintifyHttpBase();
        Http::fake([
            'printify.test/v1/shops/101/products.json*' => Http::response([
                'data' => [],
                'last_page' => 1,
            ]),
        ]);

        $job = new EnsurePrintifyAccountDefaultSkusJob($account->id);
        $job->handle(app(PrintifyDefaultSkuEnsurer::class));

        $this->assertNull($shop->fresh()->default_sku);
    }
}
