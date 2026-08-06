<?php

// db-refresh-allow: isolated sqlite DatabaseMigrations

namespace Tests\Feature;

use App\Models\PrintifyOrder;
use App\Models\PrintifyProduct;
use App\Models\PrintifyShop;
use App\Services\Printify\PrintifySyncService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\InteractsWithPrintifyAccounts;
use Tests\TestCase;

class PrintifySyncTest extends TestCase
{
    use DatabaseMigrations;
    use InteractsWithPrintifyAccounts;

    public function test_limited_sync_marks_orders_sync_incomplete_but_does_not_gate_creation(): void
    {
        $account = $this->makePrintifyAccount();
        $shop = $this->makePrintifyShop($account, [
            'default_sku' => 'READY-SKU',
            'manual_approval_confirmed_at' => now(),
        ]);
        $this->configurePrintifyHttpBase();
        Http::fake(['printify.test/*' => Http::response(['data' => [], 'last_page' => 1])]);

        app(PrintifySyncService::class)->syncOrders($account, 101, 1);

        $this->assertSame('incomplete', $shop->fresh()->orders_sync_state);
        $this->assertTrue($shop->fresh()->isReadyForCreation());
        $this->assertNotContains('orders_sync_incomplete', $shop->fresh()->readinessIssues());
    }

    public function test_pending_orders_sync_does_not_block_ready_shop(): void
    {
        $account = $this->makePrintifyAccount();
        $shop = $this->makePrintifyShop($account, [
            'default_sku' => 'READY-SKU',
            'orders_sync_state' => 'pending',
            'manual_approval_confirmed_at' => now(),
        ]);

        $this->assertTrue($shop->fresh()->isReadyForCreation());
        $this->assertSame([], $shop->fresh()->readinessIssues());
    }

    public function test_exhaustive_sync_marks_shop_complete_but_needs_manual_confirmation(): void
    {
        $account = $this->makePrintifyAccount();
        $shop = $this->makePrintifyShop($account);
        $this->configurePrintifyHttpBase();
        Http::fake(['printify.test/*' => Http::response(['data' => [], 'last_page' => 1])]);

        app(PrintifySyncService::class)->syncOrders($account, 101);

        $this->assertSame('complete', $shop->fresh()->orders_sync_state);
        $this->assertFalse($shop->fresh()->isReadyForCreation());
    }

    public function test_reactivated_shop_requires_a_new_exhaustive_sync_and_manual_confirmation(): void
    {
        $account = $this->makePrintifyAccount();
        $shop = $this->makePrintifyShop($account, [
            'is_active' => false,
            'orders_sync_state' => 'complete',
            'manual_approval_confirmed_at' => now(),
        ]);
        $this->configurePrintifyHttpBase();
        Http::fake(['printify.test/*' => Http::response([['id' => 101, 'title' => 'Primary']])]);

        app(PrintifySyncService::class)->syncShops($account);

        $this->assertSame('pending', $shop->fresh()->orders_sync_state);
        $this->assertNull($shop->fresh()->manual_approval_confirmed_at);
    }

    public function test_same_external_order_in_two_shops_is_marked_as_conflict(): void
    {
        $account = $this->makePrintifyAccount();
        $first = $this->makePrintifyShop($account, ['printify_shop_id' => 101, 'title' => 'First']);
        PrintifyOrder::create(['printify_shop_id' => $first->id, 'printify_order_id' => 'existing', 'ebay_order_number' => '13-14975-00010']);
        $this->makePrintifyShop($account, ['printify_shop_id' => 102, 'title' => 'Second']);
        $this->configurePrintifyHttpBase();
        Http::fake(['printify.test/*' => Http::response(['data' => [['id' => 'new', 'external_id' => '13-14975-00010', 'status' => 'pending']], 'last_page' => 1])]);

        app(PrintifySyncService::class)->syncOrders($account, 102);

        $this->assertSame(2, PrintifyOrder::where('has_conflict', true)->count());
        $this->assertSame('conflict', PrintifyShop::where('printify_shop_id', 102)->value('orders_sync_state'));
    }

    public function test_account_lock_prevents_overlapping_order_syncs(): void
    {
        $account = $this->makePrintifyAccount();
        $this->makePrintifyShop($account);
        $lock = Cache::lock("printify:sync:account:{$account->id}", 60);
        $this->assertTrue($lock->get());
        $this->configurePrintifyHttpBase();

        try {
            $this->expectException(RuntimeException::class);
            app(PrintifySyncService::class)->syncOrders($account, 101);
        } finally {
            $lock->release();
        }
    }

    public function test_shop_lock_is_account_scoped(): void
    {
        $account = $this->makePrintifyAccount();
        $this->makePrintifyShop($account);
        $lock = Cache::lock("printify:sync:shop:{$account->id}:101", 60);
        $this->assertTrue($lock->get());
        $this->configurePrintifyHttpBase();

        try {
            $this->expectException(RuntimeException::class);
            app(PrintifySyncService::class)->syncOrders($account, 101);
        } finally {
            $lock->release();
        }
    }

    public function test_ready_shop_does_not_wait_on_other_shops_sync_state(): void
    {
        $account = $this->makePrintifyAccount();
        $readyCandidate = $this->makePrintifyShop($account, [
            'printify_shop_id' => 101,
            'title' => 'First',
            'orders_sync_state' => 'complete',
            'manual_approval_confirmed_at' => now(),
            'default_sku' => 'READY-SKU',
        ]);
        $this->makePrintifyShop($account, [
            'printify_shop_id' => 102,
            'title' => 'Second',
            'orders_sync_state' => 'pending',
        ]);

        $this->assertTrue($readyCandidate->fresh()->isReadyForCreation());
        $this->assertFalse(PrintifyShop::where('printify_shop_id', 102)->first()->isReadyForCreation());
    }

    public function test_shop_without_default_sku_is_not_ready_for_creation(): void
    {
        $account = $this->makePrintifyAccount();
        $shop = $this->makePrintifyShop($account, [
            'title' => 'No default',
            'orders_sync_state' => 'complete',
            'manual_approval_confirmed_at' => now(),
            'default_sku' => null,
        ]);

        $this->assertFalse($shop->fresh()->isReadyForCreation());
        $this->assertContains('missing_default_sku', $shop->fresh()->readinessIssues());
    }

    public function test_inactive_account_is_not_ready_for_creation(): void
    {
        $account = $this->makePrintifyAccount('inactive@example.com', 'pat', false);
        $shop = $this->makePrintifyShop($account, [
            'default_sku' => 'READY-SKU',
            'orders_sync_state' => 'complete',
            'manual_approval_confirmed_at' => now(),
        ]);

        $this->assertFalse($shop->fresh()->isReadyForCreation());
        $this->assertContains('account_inactive', $shop->fresh()->readinessIssues());
    }

    public function test_closed_shop_is_not_ready_for_creation(): void
    {
        $account = $this->makePrintifyAccount();
        $shop = $this->makePrintifyShop($account, [
            'title' => 'Closed',
            'is_open' => false,
            'orders_sync_state' => 'complete',
            'manual_approval_confirmed_at' => now(),
        ]);

        $this->assertFalse($shop->fresh()->isReadyForCreation());
    }

    public function test_account_lock_prevents_shop_sync_overlapping_order_sync(): void
    {
        $account = $this->makePrintifyAccount();
        $lock = Cache::lock("printify:sync:account:{$account->id}", 60);
        $this->assertTrue($lock->get());
        $this->configurePrintifyHttpBase();

        try {
            $this->expectException(RuntimeException::class);
            app(PrintifySyncService::class)->syncShops($account);
        } finally {
            $lock->release();
        }
    }

    public function test_conflicted_order_blocks_an_otherwise_ready_shop(): void
    {
        $account = $this->makePrintifyAccount();
        $shop = $this->makePrintifyShop($account, [
            'orders_sync_state' => 'complete',
            'manual_approval_confirmed_at' => now(),
        ]);
        PrintifyOrder::create([
            'printify_shop_id' => $shop->id,
            'printify_order_id' => 'conflicted-order',
            'has_conflict' => true,
        ]);

        $this->assertFalse($shop->fresh()->isReadyForCreation());
    }

    public function test_account_lock_prevents_product_sync(): void
    {
        $account = $this->makePrintifyAccount();
        $this->makePrintifyShop($account);
        $lock = Cache::lock("printify:sync:account:{$account->id}", 60);
        $this->assertTrue($lock->get());
        $this->configurePrintifyHttpBase();

        try {
            $this->expectException(RuntimeException::class);
            app(PrintifySyncService::class)->syncProducts($account, 101);
        } finally {
            $lock->release();
        }
    }

    public function test_account_lock_prevents_upload_sync(): void
    {
        $account = $this->makePrintifyAccount();
        $lock = Cache::lock("printify:sync:account:{$account->id}", 60);
        $this->assertTrue($lock->get());
        $this->configurePrintifyHttpBase();

        try {
            $this->expectException(RuntimeException::class);
            app(PrintifySyncService::class)->syncUploads($account);
        } finally {
            $lock->release();
        }
    }

    public function test_sync_product_upserts_one_product_and_variants(): void
    {
        $account = $this->makePrintifyAccount();
        $this->makePrintifyShop($account);
        $this->configurePrintifyHttpBase();
        Http::fake([
            'printify.test/v1/shops/101/products/abc123.json' => Http::response([
                'id' => 'abc123',
                'title' => 'Placeholder Tee',
                'visible' => true,
                'blueprint_id' => 5,
                'print_provider_id' => 9,
                'variants' => [
                    ['id' => 1, 'sku' => 'DEF-SKU-1', 'title' => 'S', 'is_enabled' => true, 'price' => 1999],
                ],
            ]),
        ]);

        $product = app(PrintifySyncService::class)->syncProduct($account, 101, 'abc123');

        $this->assertSame('abc123', $product->printify_product_id);
        $this->assertSame('Placeholder Tee', $product->title);
        $this->assertCount(1, $product->variants);
        $this->assertSame('DEF-SKU-1', $product->variants->first()->sku);
    }

    public function test_sync_products_respects_max_products(): void
    {
        $account = $this->makePrintifyAccount();
        $this->makePrintifyShop($account);
        $this->configurePrintifyHttpBase();
        Http::fake([
            'printify.test/v1/shops/101/products.json*' => Http::response([
                'data' => [
                    ['id' => 'p1', 'title' => 'One', 'variants' => [['id' => 1, 'sku' => 'A', 'is_enabled' => true]]],
                    ['id' => 'p2', 'title' => 'Two', 'variants' => [['id' => 2, 'sku' => 'B', 'is_enabled' => true]]],
                ],
                'last_page' => 1,
            ]),
        ]);

        $count = app(PrintifySyncService::class)->syncProducts($account, 101, 1, 1);

        $this->assertSame(1, $count);
        $this->assertSame(1, PrintifyProduct::count());
    }

    public function test_cross_account_shop_sync_throws_instead_of_silent_skip(): void
    {
        $accountA = $this->makePrintifyAccount('a@example.com', 'token-a');
        $accountB = $this->makePrintifyAccount('b@example.com', 'token-b');
        $this->makePrintifyShop($accountB, ['printify_shop_id' => 101, 'title' => 'Owned by B']);
        $this->configurePrintifyHttpBase();
        Http::fake([
            'printify.test/v1/shops.json' => Http::response([
                ['id' => 101, 'title' => 'Hijack'],
            ]),
        ]);

        try {
            app(PrintifySyncService::class)->syncShops($accountA);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('another account', $e->getMessage());
        }

        $this->assertDatabaseHas('printify_shops', [
            'printify_shop_id' => 101,
            'printify_account_id' => $accountB->id,
            'title' => 'Owned by B',
        ]);
    }
}
