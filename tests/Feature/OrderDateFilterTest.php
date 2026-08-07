<?php

// db-refresh-allow: matches existing Feature suite; phpunit uses isolated sqlite :memory: via DatabaseMigrations

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderDateFilterTest extends TestCase
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

    public function test_ebay_date_filters_scope_ebay_created_at(): void
    {
        $this->actingAdmin();

        $in = $this->makeOrder([
            'ebay_order_id' => 'EBAY-IN',
            'ebay_order_number' => 'EBAY-IN',
            'ebay_created_at' => '2026-08-10 10:00:00',
        ]);
        $this->makeOrder([
            'ebay_order_id' => 'EBAY-OUT',
            'ebay_order_number' => 'EBAY-OUT',
            'ebay_created_at' => '2026-07-01 10:00:00',
        ]);

        $ids = collect(
            $this->getJson('/api/orders?from_date=2026-08-01&to_date=2026-08-31')
                ->assertOk()
                ->json('data.data')
        )->pluck('id');

        $this->assertTrue($ids->contains($in->id));
        $this->assertCount(1, $ids);
    }

    public function test_printify_date_filters_scope_printify_created_at_and_exclude_null(): void
    {
        $this->actingAdmin();

        $in = $this->makeOrder([
            'ebay_order_id' => 'PFY-IN',
            'ebay_order_number' => 'PFY-IN',
            'printify_created_at' => '2026-08-15 10:00:00',
        ]);
        $this->makeOrder([
            'ebay_order_id' => 'PFY-OUT',
            'ebay_order_number' => 'PFY-OUT',
            'printify_created_at' => '2026-07-15 10:00:00',
        ]);
        $this->makeOrder([
            'ebay_order_id' => 'PFY-NULL',
            'ebay_order_number' => 'PFY-NULL',
            'printify_created_at' => null,
        ]);

        $ids = collect(
            $this->getJson('/api/orders?printify_from_date=2026-08-01&printify_to_date=2026-08-31')
                ->assertOk()
                ->json('data.data')
        )->pluck('id');

        $this->assertTrue($ids->contains($in->id));
        $this->assertCount(1, $ids);
    }
}
