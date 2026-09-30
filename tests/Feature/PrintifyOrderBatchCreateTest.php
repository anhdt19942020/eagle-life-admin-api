<?php

// db-refresh-allow: isolated sqlite DatabaseMigrations

namespace Tests\Feature;

use App\Jobs\CreatePrintifyOrderJob;
use App\Models\Order;
use App\Models\PrintifyOrder;
use App\Models\PrintifyProduct;
use App\Models\PrintifyProductVariant;
use App\Models\PrintifyShop;
use App\Models\User;
use App\Services\OrderImportService;
use App\Services\Printify\PrintifyOrderCreateService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\InteractsWithPrintifyAccounts;
use Tests\TestCase;

class PrintifyOrderBatchCreateTest extends TestCase
{
    use DatabaseMigrations;
    use InteractsWithPrintifyAccounts;

    protected function tearDown(): void
    {
        // Down migration rejects queued intent rows; each test owns its isolated SQLite database.
        PrintifyOrder::whereNull('printify_order_id')->delete();
        parent::tearDown();
    }

    private function readyShop(): PrintifyShop
    {
        $account = $this->makePrintifyAccount();

        return $this->makePrintifyShop($account, [
            'printify_shop_id' => 101,
            'title' => 'Primary',
            'default_sku' => 'TEST-DEFAULT-SKU',
            'orders_sync_state' => 'complete',
            'manual_approval_confirmed_at' => now(),
        ]);
    }

    private function actingAdminCreator(): User
    {
        $user = User::factory()->create();
        Role::findOrCreate('admin', 'api');
        Permission::findOrCreate('printify.order.create', 'api');
        $user->assignRole('admin');
        $user->givePermissionTo('printify.order.create');
        Sanctum::actingAs($user);

        return $user;
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

    private function importOrderWithSku(string $sku, string $orderNumber = '13-14975-00010'): Order
    {
        $csv = "Order Number,Sale Date,Transaction ID,Item Number,Item Title,Custom Label,Variation Details,Quantity,Sold For,Shipping And Handling,Total Price,Buyer Username,Buyer Name,Buyer Email,Ship To Name,Ship To Phone,Ship To Address 1,Ship To Address 2,Ship To City,Ship To State,Ship To Zip,Ship To Country\n"
            ."{$orderNumber},Aug-02-26,T-1,123,Shirt,{$sku},[Size:M],1,\$10.00,\$0.00,\$10.00,harharrlind,Lindsey Harris,buyer@members.ebay.com,Lindsey Harris,+1 479-692-3507,4168 SR 326,,Russellville,AR,72802-1427,US\n";

        app(OrderImportService::class)->importFromCsv(
            UploadedFile::fake()->createWithContent("orders-{$orderNumber}.csv", $csv),
            null
        );

        return Order::with(['lineItems', 'fulfillmentAddress'])->where('ebay_order_number', $orderNumber)->firstOrFail();
    }

    // --- Batch endpoint: enqueue only, no Printify call ---

    public function test_batch_enqueues_ready_orders_and_dispatches_one_job_each(): void
    {
        Queue::fake();
        $this->actingAdminCreator();
        $shop = $this->readyShop();
        $this->seedMappedVariant($shop);
        $orderA = $this->importOrderWithSku('SKU-M', '13-14975-00001');
        $orderB = $this->importOrderWithSku('SKU-M', '13-14975-00002');

        $this->postJson('/api/orders/printify-create-batch', [
            'shop_id' => $shop->id,
            'order_ids' => [$orderA->id, $orderB->id],
        ])->assertOk()
            ->assertJsonPath('data.results.0.state', 'queued')
            ->assertJsonPath('data.results.1.state', 'queued');

        $this->assertSame(2, PrintifyOrder::where('intent_state', 'queued')->count());
        $this->assertNull(PrintifyOrder::first()->status);
        Queue::assertPushed(CreatePrintifyOrderJob::class, 2);
        Queue::assertPushedOn('printify-orders', CreatePrintifyOrderJob::class);
    }

    public function test_batch_double_submit_reports_already_queued_without_new_rows(): void
    {
        Queue::fake();
        $this->actingAdminCreator();
        $shop = $this->readyShop();
        $this->seedMappedVariant($shop);
        $order = $this->importOrderWithSku('SKU-M');

        $this->postJson('/api/orders/printify-create-batch', ['shop_id' => $shop->id, 'order_ids' => [$order->id]])->assertOk();
        $this->postJson('/api/orders/printify-create-batch', ['shop_id' => $shop->id, 'order_ids' => [$order->id]])
            ->assertOk()
            ->assertJsonPath('data.results.0.state', 'already_queued');

        $this->assertSame(1, PrintifyOrder::count());
        Queue::assertPushed(CreatePrintifyOrderJob::class, 1);
    }

    public function test_batch_requeues_an_orphaned_queued_row(): void
    {
        Queue::fake();
        $this->actingAdminCreator();
        $shop = $this->readyShop();
        $this->seedMappedVariant($shop);
        $order = $this->importOrderWithSku('SKU-M');
        $row = PrintifyOrder::create([
            'order_id' => $order->id,
            'printify_shop_id' => $shop->id,
            'printify_order_id' => null,
            'ebay_order_number' => '13-14975-00010',
            'intent_state' => 'queued',
        ]);
        $row->timestamps = false;
        $row->updated_at = now()->subMinutes(5);
        $row->save();

        $this->postJson('/api/orders/printify-create-batch', ['shop_id' => $shop->id, 'order_ids' => [$order->id]])
            ->assertOk()
            ->assertJsonPath('data.results.0.state', 'requeued');

        $this->assertSame(1, PrintifyOrder::count());
        Queue::assertPushed(CreatePrintifyOrderJob::class, 1);
    }

    public function test_batch_requeues_a_failed_row_to_a_different_shop(): void
    {
        Queue::fake();
        $this->actingAdminCreator();
        $shopA = $this->readyShop();
        $accountB = $this->makePrintifyAccount('b@example.com', 'token-b');
        $shopB = $this->makePrintifyShop($accountB, [
            'printify_shop_id' => 202,
            'title' => 'B',
            'default_sku' => 'TEST-DEFAULT-SKU',
            'orders_sync_state' => 'complete',
            'manual_approval_confirmed_at' => now(),
        ]);
        $this->seedMappedVariant($shopB);
        $order = $this->importOrderWithSku('SKU-M');
        PrintifyOrder::create([
            'order_id' => $order->id,
            'printify_shop_id' => $shopA->id,
            'printify_order_id' => null,
            'ebay_order_number' => '13-14975-00010',
            'intent_state' => 'failed',
            'last_error' => 'boom',
        ]);

        $this->postJson('/api/orders/printify-create-batch', ['shop_id' => $shopB->id, 'order_ids' => [$order->id]])
            ->assertOk()
            ->assertJsonPath('data.results.0.state', 'requeued');

        $this->assertSame(1, PrintifyOrder::count());
        $row = PrintifyOrder::first();
        $this->assertSame($shopB->id, $row->printify_shop_id);
        $this->assertSame('queued', $row->intent_state);
        $this->assertNull($row->last_error);
    }

    public function test_batch_reports_conflict_row_without_dispatching(): void
    {
        Queue::fake();
        $this->actingAdminCreator();
        $shop = $this->readyShop();
        $this->seedMappedVariant($shop);
        $order = $this->importOrderWithSku('SKU-M');
        PrintifyOrder::create([
            'order_id' => $order->id,
            'printify_shop_id' => $shop->id,
            'printify_order_id' => 'other-shop-order',
            'ebay_order_number' => '13-14975-00010',
            'intent_state' => 'conflict',
            'has_conflict' => true,
        ]);

        $this->postJson('/api/orders/printify-create-batch', ['shop_id' => $shop->id, 'order_ids' => [$order->id]])
            ->assertOk()
            ->assertJsonPath('data.results.0.state', 'conflict');

        Queue::assertNotPushed(CreatePrintifyOrderJob::class);
    }

    public function test_batch_reports_not_ready_for_unmapped_sku_without_a_row(): void
    {
        Queue::fake();
        $this->actingAdminCreator();
        $shop = $this->readyShop();
        $order = $this->importOrderWithSku('UNKNOWN-SKU');

        $this->postJson('/api/orders/printify-create-batch', ['shop_id' => $shop->id, 'order_ids' => [$order->id]])
            ->assertOk()
            ->assertJsonPath('data.results.0.state', 'not_ready');

        $this->assertSame(0, PrintifyOrder::count());
        Queue::assertNotPushed(CreatePrintifyOrderJob::class);
    }

    public function test_batch_reports_not_found_for_an_order_outside_visibility(): void
    {
        Queue::fake();
        $this->actingAdminCreator();
        $shop = $this->readyShop();
        $this->seedMappedVariant($shop);
        $order = $this->importOrderWithSku('SKU-M');

        $this->postJson('/api/orders/printify-create-batch', ['shop_id' => $shop->id, 'order_ids' => [999999]])
            ->assertOk()
            ->assertJsonPath('data.results.0.state', 'not_found')
            ->assertJsonPath('data.results.0.order_id', 999999);
    }

    // --- Job: CAS, success, error classification ---

    private function queuedRow(PrintifyShop $shop, Order $order): PrintifyOrder
    {
        return PrintifyOrder::create([
            'order_id' => $order->id,
            'printify_shop_id' => $shop->id,
            'printify_order_id' => null,
            'ebay_order_number' => $order->ebay_order_number,
            'intent_state' => 'queued',
        ]);
    }

    public function test_job_success_marks_created_and_backfills_order(): void
    {
        $this->configurePrintifyHttpBase();
        $shop = $this->readyShop();
        $this->seedMappedVariant($shop);
        $order = $this->importOrderWithSku('SKU-M');
        $row = $this->queuedRow($shop, $order);
        Http::fake(['printify.test/v1/shops/101/orders.json' => Http::response(['id' => 'pog-1', 'status' => 'pending'], 200)]);

        (new CreatePrintifyOrderJob($row->id))->handle(app(PrintifyOrderCreateService::class));

        $row->refresh();
        $this->assertSame('created', $row->intent_state);
        $this->assertSame('pog-1', $row->printify_order_id);
        $this->assertSame('pog-1', $order->fresh()->printify_order_id);
    }

    public function test_job_connection_exception_releases_row_to_queued_not_failed(): void
    {
        $this->configurePrintifyHttpBase();
        $shop = $this->readyShop();
        $this->seedMappedVariant($shop);
        $order = $this->importOrderWithSku('SKU-M');
        $row = $this->queuedRow($shop, $order);
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: timeout for https://example.test/?token=private-token&email=buyer@example.test');
        });

        $job = (new CreatePrintifyOrderJob($row->id))->withFakeQueueInteractions();
        $job->handle(app(PrintifyOrderCreateService::class));
        $job->assertReleased(10);

        $this->assertSame('queued', $row->fresh()->intent_state);
        $this->assertStringContainsString('cURL error 28', $row->fresh()->last_error);
        $this->assertStringNotContainsString('private-token', $row->fresh()->last_error);
        $this->assertStringNotContainsString('buyer@example.test', $row->fresh()->last_error);
    }

    public function test_job_429_releases_row_to_queued(): void
    {
        $this->configurePrintifyHttpBase();
        $shop = $this->readyShop();
        $this->seedMappedVariant($shop);
        $order = $this->importOrderWithSku('SKU-M');
        $row = $this->queuedRow($shop, $order);
        Http::fake(['printify.test/v1/shops/101/orders.json' => Http::response(['error' => 'rate limited'], 429)]);

        $job = (new CreatePrintifyOrderJob($row->id))->withFakeQueueInteractions();
        $job->handle(app(PrintifyOrderCreateService::class));
        $job->assertReleased(10);

        $this->assertSame('queued', $row->fresh()->intent_state);
        $this->assertStringContainsString('HTTP 429', $row->fresh()->last_error);
    }

    public function test_job_permanent_4xx_marks_failed_with_reason(): void
    {
        $this->configurePrintifyHttpBase();
        $shop = $this->readyShop();
        $this->seedMappedVariant($shop);
        $order = $this->importOrderWithSku('SKU-M');
        $row = $this->queuedRow($shop, $order);
        Http::fake(['printify.test/v1/shops/101/orders.json' => Http::response(['message' => 'Invalid address'], 422)]);

        (new CreatePrintifyOrderJob($row->id))->handle(app(PrintifyOrderCreateService::class));

        $row->refresh();
        $this->assertSame('failed', $row->intent_state);
        $this->assertStringContainsString('Invalid address', $row->last_error);
    }

    public function test_job_409_reconciles_with_a_single_page_call(): void
    {
        $this->configurePrintifyHttpBase();
        $shop = $this->readyShop();
        $this->seedMappedVariant($shop);
        $order = $this->importOrderWithSku('SKU-M');
        $row = $this->queuedRow($shop, $order);
        Http::fake([
            'printify.test/v1/shops/101/orders.json*' => function ($request) use ($order) {
                if ($request->method() === 'POST') {
                    return Http::response(['error' => 'Order already exists for the given external_id.'], 409);
                }

                return Http::response(['data' => [[
                    'id' => 'pog-remote',
                    'external_id' => $order->ebay_order_number,
                    'status' => 'on-hold',
                ]], 'last_page' => 1], 200);
            },
        ]);

        (new CreatePrintifyOrderJob($row->id))->handle(app(PrintifyOrderCreateService::class));

        $this->assertSame('synced', $row->fresh()->intent_state);
        $this->assertSame('pog-remote', $row->fresh()->printify_order_id);
        Http::assertSentCount(2); // 1 POST + 1 reconcile GET (single page)
    }

    public function test_job_lock_busy_releases_row_to_queued(): void
    {
        $shop = $this->readyShop();
        $this->seedMappedVariant($shop);
        $order = $this->importOrderWithSku('SKU-M');
        $row = $this->queuedRow($shop, $order);
        $lock = Cache::lock("printify:sync:account:{$shop->printify_account_id}", 60);
        $this->assertTrue($lock->get());
        $this->configurePrintifyHttpBase();
        Http::fake(['printify.test/v1/shops/101/orders.json' => Http::response(['error' => 'Order already exists for the given external_id.'], 409)]);

        try {
            $job = (new CreatePrintifyOrderJob($row->id))->withFakeQueueInteractions();
            $job->handle(app(PrintifyOrderCreateService::class));
            $job->assertReleased(10);
        } finally {
            $lock->release();
        }

        $this->assertSame('queued', $row->fresh()->intent_state);
        $this->assertStringContainsString('HTTP 409', $row->fresh()->last_error);
        $this->assertStringContainsString('sync lock busy', $row->fresh()->last_error);
    }

    public function test_job_ignores_a_row_another_worker_already_claimed(): void
    {
        $shop = $this->readyShop();
        $this->seedMappedVariant($shop);
        $order = $this->importOrderWithSku('SKU-M');
        $row = $this->queuedRow($shop, $order);
        $row->update(['intent_state' => 'processing']); // another worker owns it, updated_at is fresh
        $this->configurePrintifyHttpBase();
        Http::fake();

        (new CreatePrintifyOrderJob($row->id))->handle(app(PrintifyOrderCreateService::class));

        Http::assertNothingSent();
        $this->assertSame('processing', $row->fresh()->intent_state);
    }

    public function test_job_takes_over_a_stale_processing_row(): void
    {
        $shop = $this->readyShop();
        $this->seedMappedVariant($shop);
        $order = $this->importOrderWithSku('SKU-M');
        $row = $this->queuedRow($shop, $order);
        $row->timestamps = false;
        $row->intent_state = 'processing';
        $row->updated_at = now()->subMinutes(5);
        $row->save();
        $this->configurePrintifyHttpBase();
        Http::fake(['printify.test/v1/shops/101/orders.json' => Http::response(['id' => 'pog-recovered', 'status' => 'pending'], 200)]);

        (new CreatePrintifyOrderJob($row->id))->handle(app(PrintifyOrderCreateService::class));

        $this->assertSame('created', $row->fresh()->intent_state);
        Http::assertSentCount(1);
    }

    public function test_two_job_instances_on_same_row_post_exactly_once(): void
    {
        $shop = $this->readyShop();
        $this->seedMappedVariant($shop);
        $order = $this->importOrderWithSku('SKU-M');
        $row = $this->queuedRow($shop, $order);
        $this->configurePrintifyHttpBase();
        Http::fake(['printify.test/v1/shops/101/orders.json' => Http::response(['id' => 'pog-once', 'status' => 'pending'], 200)]);

        (new CreatePrintifyOrderJob($row->id))->handle(app(PrintifyOrderCreateService::class));
        (new CreatePrintifyOrderJob($row->id))->handle(app(PrintifyOrderCreateService::class));

        Http::assertSentCount(1);
        $this->assertSame('created', $row->fresh()->intent_state);
    }

    public function test_single_endpoint_on_queued_row_reports_state_without_already_exists_message(): void
    {
        $this->actingAdminCreator();
        $shop = $this->readyShop();
        $this->seedMappedVariant($shop);
        $order = $this->importOrderWithSku('SKU-M');
        // Row already queued by another process (stale-but-not-orphan window).
        PrintifyOrder::create([
            'order_id' => $order->id,
            'printify_shop_id' => $shop->id,
            'printify_order_id' => null,
            'ebay_order_number' => '13-14975-00010',
            'intent_state' => 'queued',
        ]);
        $this->configurePrintifyHttpBase();
        Http::fake();

        $this->postJson("/api/orders/{$order->id}/printify-create", ['shop_id' => $shop->id])
            ->assertOk()
            ->assertJsonPath('data.state', 'already_queued')
            ->assertJsonPath('message', 'Đơn đang chờ tạo trên Printify');

        Http::assertNothingSent();
    }

    public function test_single_retry_submits_once_without_dispatching_a_second_job(): void
    {
        Queue::fake();
        $this->actingAdminCreator();
        $shop = $this->readyShop();
        $this->seedMappedVariant($shop);
        $order = $this->importOrderWithSku('SKU-M');
        $row = $this->queuedRow($shop, $order);
        $row->update(['intent_state' => 'failed']);
        $this->configurePrintifyHttpBase();
        Http::fake(['printify.test/v1/shops/101/orders.json' => Http::response(['id' => 'pog-retry', 'status' => 'pending'])]);

        $this->postJson("/api/orders/{$order->id}/printify-create", ['shop_id' => $shop->id])
            ->assertOk()
            ->assertJsonPath('data.state', 'created');

        Queue::assertNotPushed(CreatePrintifyOrderJob::class);
        Http::assertSentCount(1);
        $this->assertSame('pog-retry', $row->fresh()->printify_order_id);
    }

    public function test_remote_conflict_remains_authoritative_over_a_late_job_success(): void
    {
        $this->configurePrintifyHttpBase();
        $shop = $this->readyShop();
        $this->seedMappedVariant($shop);
        $order = $this->importOrderWithSku('SKU-M');
        $row = $this->queuedRow($shop, $order);
        Http::fake(function () use ($row) {
            $row->update(['intent_state' => 'conflict', 'status' => 'on-hold', 'printify_order_id' => 'other-shop-order']);

            return Http::response(['id' => 'late-order', 'status' => 'pending']);
        });

        (new CreatePrintifyOrderJob($row->id))->handle(app(PrintifyOrderCreateService::class));

        $this->assertSame('conflict', $row->fresh()->intent_state);
        $this->assertSame('other-shop-order', $row->fresh()->printify_order_id);
        $this->assertNull($order->fresh()->printify_order_id);
    }

    public function test_remote_sync_remains_authoritative_over_a_late_retryable_error(): void
    {
        $this->configurePrintifyHttpBase();
        $shop = $this->readyShop();
        $this->seedMappedVariant($shop);
        $order = $this->importOrderWithSku('SKU-M');
        $row = $this->queuedRow($shop, $order);
        Http::fake(function () use ($row) {
            $row->update(['intent_state' => 'synced', 'status' => 'on-hold', 'printify_order_id' => 'remote-order']);

            return Http::response(['error' => 'rate limited'], 429);
        });

        $job = (new CreatePrintifyOrderJob($row->id))->withFakeQueueInteractions();
        $job->handle(app(PrintifyOrderCreateService::class));

        $job->assertNotReleased();
        $this->assertSame('synced', $row->fresh()->intent_state);
        $this->assertSame('remote-order', $row->fresh()->printify_order_id);
        $job->failed(new \RuntimeException('late worker failure'));
        $this->assertSame('synced', $row->fresh()->intent_state);
        $this->assertNull($row->fresh()->last_error);
    }

    public function test_last_retry_attempt_fails_instead_of_releasing_again(): void
    {
        $this->configurePrintifyHttpBase();
        $shop = $this->readyShop();
        $this->seedMappedVariant($shop);
        $order = $this->importOrderWithSku('SKU-M');
        $row = $this->queuedRow($shop, $order);
        Http::fake(['printify.test/v1/shops/101/orders.json' => Http::response(['error' => 'rate limited'], 429)]);
        $job = (new CreatePrintifyOrderJob($row->id))->withFakeQueueInteractions();
        $job->job->attempts = $job->tries;

        $job->handle(app(PrintifyOrderCreateService::class));

        $job->assertFailed();
        $job->assertNotReleased();
        $this->assertStringContainsString('HTTP 429', $job->job->failedWith->getMessage());
    }

    public function test_single_transient_create_dispatches_one_retry_job(): void
    {
        Queue::fake();
        $this->actingAdminCreator();
        $shop = $this->readyShop();
        $this->seedMappedVariant($shop);
        $order = $this->importOrderWithSku('SKU-M');
        $this->configurePrintifyHttpBase();
        Http::fake(['printify.test/v1/shops/101/orders.json' => Http::response(['error' => 'rate limited'], 429)]);

        $this->postJson("/api/orders/{$order->id}/printify-create", ['shop_id' => $shop->id])
            ->assertOk()
            ->assertJsonPath('data.state', 'queued')
            ->assertJsonPath('data.printify_order.last_error', fn ($reason) => is_string($reason) && str_contains($reason, 'HTTP 429'));

        Queue::assertPushed(CreatePrintifyOrderJob::class, 1);
        $this->assertSame('queued', PrintifyOrder::first()->intent_state);
        Http::assertSentCount(1);
    }

    public function test_retry_reason_is_updated_then_cleared_after_success(): void
    {
        $this->configurePrintifyHttpBase();
        $shop = $this->readyShop();
        $this->seedMappedVariant($shop);
        $order = $this->importOrderWithSku('SKU-M');
        $row = $this->queuedRow($shop, $order);
        Http::fake(['printify.test/v1/shops/101/orders.json' => Http::sequence()
            ->push(['message' => 'rate limited'], 429)
            ->push(['message' => 'unavailable private-token buyer@example.test'], 503)
            ->push(['id' => 'remote-recovered', 'status' => 'pending'])]);
        $job = (new CreatePrintifyOrderJob($row->id))->withFakeQueueInteractions();

        $job->handle(app(PrintifyOrderCreateService::class));
        $this->assertStringContainsString('HTTP 429', $row->fresh()->last_error);
        $job->handle(app(PrintifyOrderCreateService::class));
        $this->assertStringContainsString('HTTP 503', $row->fresh()->last_error);
        $this->assertStringNotContainsString('HTTP 429', $row->fresh()->last_error);
        $this->assertStringNotContainsString('private-token', $row->fresh()->last_error);
        $this->assertStringNotContainsString('buyer@example.test', $row->fresh()->last_error);

        $job->handle(app(PrintifyOrderCreateService::class));
        $this->assertSame('created', $row->fresh()->intent_state);
        $this->assertSame('remote-recovered', $row->fresh()->printify_order_id);
        $this->assertNull($row->fresh()->last_error);
    }

    public function test_reconciliation_miss_preserves_duplicate_order_reason(): void
    {
        $this->configurePrintifyHttpBase();
        $shop = $this->readyShop();
        $this->seedMappedVariant($shop);
        $order = $this->importOrderWithSku('SKU-M');
        $row = $this->queuedRow($shop, $order);
        Http::fake(['printify.test/v1/shops/101/orders.json*' => Http::sequence()
            ->push(['error' => 'Order already exists for the given external_id.'], 409)
            ->push(['data' => [], 'last_page' => 2])]);

        $job = (new CreatePrintifyOrderJob($row->id))->withFakeQueueInteractions();
        $job->handle(app(PrintifyOrderCreateService::class));
        $job->assertReleased(10);

        $this->assertStringContainsString('HTTP 409', $row->fresh()->last_error);
        $this->assertStringContainsString('not found on first page', $row->fresh()->last_error);
    }

    public function test_failed_callback_preserves_retry_reason_in_row_and_error_log(): void
    {
        $this->configurePrintifyHttpBase();
        $shop = $this->readyShop();
        $this->seedMappedVariant($shop);
        $order = $this->importOrderWithSku('SKU-M');
        $row = $this->queuedRow($shop, $order);
        Http::fake(['printify.test/v1/shops/101/orders.json' => Http::response(['error' => 'rate limited'], 429)]);
        $job = (new CreatePrintifyOrderJob($row->id))->withFakeQueueInteractions();
        $job->handle(app(PrintifyOrderCreateService::class));
        Log::spy();

        $job->failed(new \RuntimeException('retry limit reached private-token buyer@example.test'));

        $this->assertSame('failed', $row->fresh()->intent_state);
        $this->assertStringContainsString('HTTP 429', $row->fresh()->last_error);
        $this->assertStringNotContainsString('private-token', $row->fresh()->last_error);
        Log::shouldHaveReceived('error')->once()->withArgs(function ($event, $context) use ($row) {
            return $event === 'printify_order_create.job_failed'
                && $context['printify_order_id'] === $row->id
                && str_contains($context['last_error'], 'HTTP 429')
                && ! str_contains(json_encode($context), 'private-token')
                && ! str_contains(json_encode($context), 'buyer@example.test');
        });
    }

    public function test_failed_callback_without_retry_reason_stores_safe_exception_type(): void
    {
        $shop = $this->readyShop();
        $order = $this->importOrderWithSku('SKU-M');
        $row = $this->queuedRow($shop, $order);
        Log::spy();

        (new CreatePrintifyOrderJob($row->id))->failed(new \RuntimeException('private-token buyer@example.test'));

        $this->assertSame('failed', $row->fresh()->intent_state);
        $this->assertStringContainsString('RuntimeException', $row->fresh()->last_error);
        $this->assertStringNotContainsString('private-token', $row->fresh()->last_error);
        $this->assertStringNotContainsString('buyer@example.test', $row->fresh()->last_error);
    }
}
