<?php

// db-refresh-allow: matches existing Feature suite; phpunit uses isolated sqlite :memory: via DatabaseMigrations

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderLineItem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderSummaryStatsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function actingAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        Sanctum::actingAs($user);

        return $user;
    }

    private function makeOrder(array $attrs): Order
    {
        return Order::create(array_merge([
            'ebay_order_id' => 'ORD-'.uniqid(),
            'ebay_order_number' => 'ORD-'.uniqid(),
            'ebay_created_at' => '2026-08-01 12:00:00',
        ], $attrs));
    }

    public function test_summary_totals_orders_and_ebay_amounts_across_line_items(): void
    {
        $this->actingAdmin();

        $orderA = $this->makeOrder(['ebay_order_id' => 'SUM-A', 'ebay_order_number' => 'SUM-A']);
        OrderLineItem::create([
            'order_id' => $orderA->id,
            'transaction_id' => 'TX-A1',
            'quantity' => 2,
            'unit_price' => 10.00,
            'total_amount' => 20.00,
            'shipping_amount' => 5.00,
        ]);
        OrderLineItem::create([
            'order_id' => $orderA->id,
            'transaction_id' => 'TX-A2',
            'quantity' => 1,
            'unit_price' => 15.00,
            'total_amount' => 15.00,
            'shipping_amount' => 0,
        ]);

        $orderB = $this->makeOrder(['ebay_order_id' => 'SUM-B', 'ebay_order_number' => 'SUM-B']);
        OrderLineItem::create([
            'order_id' => $orderB->id,
            'transaction_id' => 'TX-B1',
            'quantity' => 3,
            'unit_price' => 5.00,
            'total_amount' => 15.00,
            'shipping_amount' => 2.50,
        ]);

        $this->makeOrder(['ebay_order_id' => 'SUM-C-NO-ITEMS', 'ebay_order_number' => 'SUM-C-NO-ITEMS']);

        $summary = $this->getJson('/api/orders')
            ->assertOk()
            ->json('data.summary');

        $this->assertSame(3, $summary['total_orders']);
        $this->assertSame(3, $summary['total_line_items']);
        $this->assertSame(6, $summary['total_quantity']);
        $this->assertSame('50.00', $summary['total_ebay_amount']);
        $this->assertSame('7.50', $summary['total_shipping_amount']);
    }

    public function test_summary_respects_filters_including_seller_id(): void
    {
        $admin = $this->actingAdmin();
        $sellerOne = User::factory()->create();
        $sellerOne->assignRole('seller');
        $sellerTwo = User::factory()->create();
        $sellerTwo->assignRole('seller');

        $orderOne = $this->makeOrder([
            'ebay_order_id' => 'SEL-1',
            'ebay_order_number' => 'SEL-1',
            'seller_id' => $sellerOne->id,
        ]);
        OrderLineItem::create([
            'order_id' => $orderOne->id,
            'transaction_id' => 'TX-SEL-1',
            'quantity' => 1,
            'total_amount' => 100.00,
            'shipping_amount' => 10.00,
        ]);

        $orderTwo = $this->makeOrder([
            'ebay_order_id' => 'SEL-2',
            'ebay_order_number' => 'SEL-2',
            'seller_id' => $sellerTwo->id,
        ]);
        OrderLineItem::create([
            'order_id' => $orderTwo->id,
            'transaction_id' => 'TX-SEL-2',
            'quantity' => 1,
            'total_amount' => 999.00,
            'shipping_amount' => 99.00,
        ]);

        $summary = $this->getJson("/api/orders?seller_id={$sellerOne->id}")
            ->assertOk()
            ->json('data.summary');

        $this->assertSame(1, $summary['total_orders']);
        $this->assertSame('100.00', $summary['total_ebay_amount']);
        $this->assertSame('10.00', $summary['total_shipping_amount']);
    }

    public function test_summary_excludes_soft_deleted_orders_by_default(): void
    {
        $this->actingAdmin();

        $kept = $this->makeOrder(['ebay_order_id' => 'DEL-KEEP', 'ebay_order_number' => 'DEL-KEEP']);
        OrderLineItem::create([
            'order_id' => $kept->id,
            'transaction_id' => 'TX-KEEP',
            'quantity' => 1,
            'total_amount' => 30.00,
            'shipping_amount' => 3.00,
        ]);

        $deleted = $this->makeOrder(['ebay_order_id' => 'DEL-GONE', 'ebay_order_number' => 'DEL-GONE']);
        OrderLineItem::create([
            'order_id' => $deleted->id,
            'transaction_id' => 'TX-GONE',
            'quantity' => 1,
            'total_amount' => 500.00,
            'shipping_amount' => 50.00,
        ]);
        $deleted->delete();

        $summary = $this->getJson('/api/orders')
            ->assertOk()
            ->json('data.summary');

        $this->assertSame(1, $summary['total_orders']);
        $this->assertSame('30.00', $summary['total_ebay_amount']);
    }

    public function test_summary_covers_only_trashed_orders_when_trashed_only_requested(): void
    {
        $this->actingAdmin();

        $kept = $this->makeOrder(['ebay_order_id' => 'TR-KEEP', 'ebay_order_number' => 'TR-KEEP']);
        OrderLineItem::create([
            'order_id' => $kept->id,
            'transaction_id' => 'TX-TR-KEEP',
            'quantity' => 1,
            'total_amount' => 40.00,
            'shipping_amount' => 4.00,
        ]);

        $deleted = $this->makeOrder(['ebay_order_id' => 'TR-GONE', 'ebay_order_number' => 'TR-GONE']);
        OrderLineItem::create([
            'order_id' => $deleted->id,
            'transaction_id' => 'TX-TR-GONE',
            'quantity' => 1,
            'total_amount' => 60.00,
            'shipping_amount' => 6.00,
        ]);
        $deleted->delete();

        $summary = $this->getJson('/api/orders?trashed=only')
            ->assertOk()
            ->json('data.summary');

        $this->assertSame(1, $summary['total_orders']);
        $this->assertSame('60.00', $summary['total_ebay_amount']);
    }

    public function test_summary_respects_no_printify_filter(): void
    {
        $this->actingAdmin();

        $withPrintify = $this->makeOrder([
            'ebay_order_id' => 'NP-WITH',
            'ebay_order_number' => 'NP-WITH',
        ]);
        $withPrintify->forceFill(['printify_order_id' => 'PFY-123'])->save();
        OrderLineItem::create([
            'order_id' => $withPrintify->id,
            'transaction_id' => 'TX-NP-WITH',
            'quantity' => 1,
            'total_amount' => 25.00,
            'shipping_amount' => 2.50,
        ]);

        $withoutPrintify = $this->makeOrder(['ebay_order_id' => 'NP-WITHOUT', 'ebay_order_number' => 'NP-WITHOUT']);
        OrderLineItem::create([
            'order_id' => $withoutPrintify->id,
            'transaction_id' => 'TX-NP-WITHOUT',
            'quantity' => 1,
            'total_amount' => 75.00,
            'shipping_amount' => 7.50,
        ]);

        $summary = $this->getJson('/api/orders?no_printify=1')
            ->assertOk()
            ->json('data.summary');

        $this->assertSame(1, $summary['total_orders']);
        $this->assertSame('75.00', $summary['total_ebay_amount']);
    }
}
