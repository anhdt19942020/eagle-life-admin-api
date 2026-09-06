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

class OrderDailySummaryTest extends TestCase
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

    private function makeOrder(string $id, string $createdAt, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'ebay_order_id' => $id,
            'ebay_order_number' => $id,
            'ebay_created_at' => $createdAt,
        ], $attrs));
    }

    public function test_groups_orders_and_ebay_amount_by_day(): void
    {
        $this->actingAdmin();

        $day1 = $this->makeOrder('D1', '2026-08-10 09:00:00');
        OrderLineItem::create(['order_id' => $day1->id, 'transaction_id' => 'T1', 'quantity' => 1, 'total_amount' => 20.00]);
        OrderLineItem::create(['order_id' => $day1->id, 'transaction_id' => 'T2', 'quantity' => 1, 'total_amount' => 30.00]);

        $day1b = $this->makeOrder('D1B', '2026-08-10 18:00:00');
        OrderLineItem::create(['order_id' => $day1b->id, 'transaction_id' => 'T3', 'quantity' => 1, 'total_amount' => 10.00]);

        $day3 = $this->makeOrder('D3', '2026-08-12 09:00:00');
        OrderLineItem::create(['order_id' => $day3->id, 'transaction_id' => 'T4', 'quantity' => 1, 'total_amount' => 5.00]);

        $series = $this->getJson('/api/orders/daily-summary?from_date=2026-08-10&to_date=2026-08-12')
            ->assertOk()
            ->json('data');

        // Continuous range: 3 days, the gap day filled with zeros.
        $this->assertCount(3, $series);

        $this->assertSame('2026-08-10', $series[0]['date']);
        $this->assertSame(2, $series[0]['orders_count']);
        $this->assertSame('60.00', $series[0]['ebay_amount']);

        $this->assertSame('2026-08-11', $series[1]['date']);
        $this->assertSame(0, $series[1]['orders_count']);
        $this->assertSame('0.00', $series[1]['ebay_amount']);

        $this->assertSame('2026-08-12', $series[2]['date']);
        $this->assertSame(1, $series[2]['orders_count']);
        $this->assertSame('5.00', $series[2]['ebay_amount']);
    }

    public function test_excludes_soft_deleted_orders(): void
    {
        $this->actingAdmin();

        $kept = $this->makeOrder('KEEP', '2026-08-10 09:00:00');
        OrderLineItem::create(['order_id' => $kept->id, 'transaction_id' => 'TK', 'quantity' => 1, 'total_amount' => 40.00]);

        $deleted = $this->makeOrder('GONE', '2026-08-10 10:00:00');
        OrderLineItem::create(['order_id' => $deleted->id, 'transaction_id' => 'TG', 'quantity' => 1, 'total_amount' => 500.00]);
        $deleted->delete();

        $series = $this->getJson('/api/orders/daily-summary?from_date=2026-08-10&to_date=2026-08-10')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $series[0]['orders_count']);
        $this->assertSame('40.00', $series[0]['ebay_amount']);
    }

    public function test_seller_sees_only_own_orders(): void
    {
        $seller = User::factory()->create();
        $seller->assignRole('seller');
        Sanctum::actingAs($seller);

        $other = User::factory()->create();
        $other->assignRole('seller');

        $mine = $this->makeOrder('MINE', '2026-08-10 09:00:00', ['seller_id' => $seller->id]);
        OrderLineItem::create(['order_id' => $mine->id, 'transaction_id' => 'TM', 'quantity' => 1, 'total_amount' => 11.00]);

        $theirs = $this->makeOrder('THEIRS', '2026-08-10 10:00:00', ['seller_id' => $other->id]);
        OrderLineItem::create(['order_id' => $theirs->id, 'transaction_id' => 'TT', 'quantity' => 1, 'total_amount' => 999.00]);

        $series = $this->getJson('/api/orders/daily-summary?from_date=2026-08-10&to_date=2026-08-10')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $series[0]['orders_count']);
        $this->assertSame('11.00', $series[0]['ebay_amount']);
    }

    public function test_requires_date_range(): void
    {
        $this->actingAdmin();

        $this->getJson('/api/orders/daily-summary')->assertStatus(422);
        $this->getJson('/api/orders/daily-summary?from_date=2026-08-12&to_date=2026-08-10')->assertStatus(422);
    }
}
