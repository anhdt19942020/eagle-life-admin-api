<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OrderAuthorizationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_csv_import_requires_orders_import_permission(): void
    {
        $this->postJson('/api/orders/import-csv')->assertUnauthorized();

        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->postJson('/api/orders/import-csv')->assertForbidden();

        Permission::create(['name' => 'orders.import', 'guard_name' => 'api']);
        $user->givePermissionTo('orders.import');
        $this->postJson('/api/orders/import-csv')->assertUnprocessable();
    }

    public function test_seller_role_can_reach_csv_import_validation(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $seller = User::factory()->create();
        $seller->assignRole('seller');
        Sanctum::actingAs($seller);

        $this->postJson('/api/orders/import-csv')->assertUnprocessable();
    }

    public function test_seller_cannot_delete_order(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $seller = User::factory()->create();
        $seller->assignRole('seller');
        Sanctum::actingAs($seller);

        $order = Order::create([
            'ebay_order_id' => 'DEL-SELLER-1',
            'ebay_order_number' => 'DEL-SELLER-1',
            'seller_id' => $seller->id,
            'ebay_created_at' => now(),
        ]);

        $this->deleteJson('/api/orders/'.$order->id)->assertForbidden();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'deleted_at' => null,
        ]);
    }

    public function test_group_leader_can_delete_in_scope_order(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $leader = User::factory()->create();
        $leader->assignRole('group_leader');
        Sanctum::actingAs($leader);

        $order = Order::create([
            'ebay_order_id' => 'DEL-LEADER-1',
            'ebay_order_number' => 'DEL-LEADER-1',
            'seller_id' => $leader->id,
            'ebay_created_at' => now(),
        ]);

        $this->deleteJson('/api/orders/'.$order->id)->assertOk();
        $this->assertSoftDeleted('orders', ['id' => $order->id]);
    }
}
