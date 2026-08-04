<?php

namespace Tests\Feature;

use App\Models\PrintifyShop;
use App\Models\PrintifyOrder;
use App\Services\Printify\PrintifySyncService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class PrintifySyncTest extends TestCase
{
    use DatabaseMigrations;

    public function test_limited_sync_never_marks_shop_ready(): void
    {
        $shop = PrintifyShop::create(['printify_shop_id' => 101, 'title' => 'Primary']);
        config()->set('services.printify.token', 'test-pat');
        config()->set('services.printify.base_url', 'https://printify.test/v1');
        Http::fake(['printify.test/*' => Http::response(['data' => [], 'last_page' => 1])]);

        app(PrintifySyncService::class)->syncOrders(101, 1);

        $this->assertSame('incomplete', $shop->fresh()->orders_sync_state);
        $this->assertFalse($shop->fresh()->isReadyForCreation());
    }

    public function test_exhaustive_sync_marks_shop_complete_but_needs_manual_confirmation(): void
    {
        $shop = PrintifyShop::create(['printify_shop_id' => 101, 'title' => 'Primary']);
        config()->set('services.printify.token', 'test-pat');
        config()->set('services.printify.base_url', 'https://printify.test/v1');
        Http::fake(['printify.test/*' => Http::response(['data' => [], 'last_page' => 1])]);

        app(PrintifySyncService::class)->syncOrders(101);

        $this->assertSame('complete', $shop->fresh()->orders_sync_state);
        $this->assertFalse($shop->fresh()->isReadyForCreation());
    }

    public function test_reactivated_shop_requires_a_new_exhaustive_sync_and_manual_confirmation(): void
    {
        $shop = PrintifyShop::create(['printify_shop_id' => 101, 'title' => 'Primary', 'is_active' => false, 'orders_sync_state' => 'complete', 'manual_approval_confirmed_at' => now()]);
        config()->set('services.printify.token', 'test-pat');
        config()->set('services.printify.base_url', 'https://printify.test/v1');
        Http::fake(['printify.test/*' => Http::response([['id' => 101, 'title' => 'Primary']])]);

        app(PrintifySyncService::class)->syncShops();

        $this->assertSame('pending', $shop->fresh()->orders_sync_state);
        $this->assertNull($shop->fresh()->manual_approval_confirmed_at);
    }

    public function test_same_external_order_in_two_shops_is_marked_as_conflict(): void
    {
        $first = PrintifyShop::create(['printify_shop_id' => 101, 'title' => 'First']);
        PrintifyOrder::create(['printify_shop_id' => $first->id, 'printify_order_id' => 'existing', 'ebay_order_number' => '13-14975-00010']);
        PrintifyShop::create(['printify_shop_id' => 102, 'title' => 'Second']);
        config()->set('services.printify.token', 'test-pat');
        config()->set('services.printify.base_url', 'https://printify.test/v1');
        Http::fake(['printify.test/*' => Http::response(['data' => [['id' => 'new', 'external_id' => '13-14975-00010', 'status' => 'pending']], 'last_page' => 1])]);

        app(PrintifySyncService::class)->syncOrders(102);

        $this->assertSame(2, PrintifyOrder::where('has_conflict', true)->count());
        $this->assertSame('conflict', PrintifyShop::where('printify_shop_id', 102)->value('orders_sync_state'));
    }

    public function test_account_lock_prevents_overlapping_order_syncs(): void
    {
        PrintifyShop::create(['printify_shop_id' => 101, 'title' => 'Primary']);
        $lock = Cache::lock('printify:sync:account', 60);
        $this->assertTrue($lock->get());
        config()->set('services.printify.token', 'test-pat');

        try {
            $this->expectException(RuntimeException::class);
            app(PrintifySyncService::class)->syncOrders(101);
        } finally {
            $lock->release();
        }
    }

    public function test_ready_shop_waits_for_every_active_shop_in_the_account(): void
    {
        $readyCandidate = PrintifyShop::create(['printify_shop_id' => 101, 'title' => 'First', 'orders_sync_state' => 'complete', 'manual_approval_confirmed_at' => now()]);
        PrintifyShop::create(['printify_shop_id' => 102, 'title' => 'Second', 'orders_sync_state' => 'pending']);

        $this->assertFalse($readyCandidate->fresh()->isReadyForCreation());
        PrintifyShop::where('printify_shop_id', 102)->update(['orders_sync_state' => 'complete']);
        $this->assertTrue($readyCandidate->fresh()->isReadyForCreation());
    }

    public function test_account_lock_prevents_shop_sync_overlapping_order_sync(): void
    {
        $lock = Cache::lock('printify:sync:account', 60);
        $this->assertTrue($lock->get());
        config()->set('services.printify.token', 'test-pat');

        try {
            $this->expectException(RuntimeException::class);
            app(PrintifySyncService::class)->syncShops();
        } finally {
            $lock->release();
        }
    }

    public function test_conflicted_order_blocks_an_otherwise_ready_shop(): void
    {
        $shop = PrintifyShop::create([
            'printify_shop_id' => 101,
            'title' => 'Primary',
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
        PrintifyShop::create(['printify_shop_id' => 101, 'title' => 'Primary']);
        $lock = Cache::lock('printify:sync:account', 60);
        $this->assertTrue($lock->get());
        config()->set('services.printify.token', 'test-pat');

        try {
            $this->expectException(RuntimeException::class);
            app(PrintifySyncService::class)->syncProducts(101);
        } finally {
            $lock->release();
        }
    }

    public function test_account_lock_prevents_upload_sync(): void
    {
        $lock = Cache::lock('printify:sync:account', 60);
        $this->assertTrue($lock->get());
        config()->set('services.printify.token', 'test-pat');

        try {
            $this->expectException(RuntimeException::class);
            app(PrintifySyncService::class)->syncUploads();
        } finally {
            $lock->release();
        }
    }
}
