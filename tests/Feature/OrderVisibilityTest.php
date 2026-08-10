<?php

// db-refresh-allow: matches existing Feature suite; phpunit uses isolated sqlite :memory: via DatabaseMigrations

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PrintifyAccount;
use App\Models\PrintifyShop;
use App\Models\SalesGroup;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderVisibilityTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function makeOrder(?int $sellerId, string $number): Order
    {
        return Order::create([
            'ebay_order_id' => $number,
            'ebay_order_number' => $number,
            'seller_id' => $sellerId,
            'ebay_created_at' => now(),
        ]);
    }

    private function actingAsRole(string $role, array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_seller_lists_only_own_orders(): void
    {
        $sellerA = $this->actingAsRole('seller');
        $sellerB = User::factory()->create();
        $sellerB->assignRole('seller');

        $own = $this->makeOrder($sellerA->id, 'ORD-A');
        $this->makeOrder($sellerB->id, 'ORD-B');
        $this->makeOrder(null, 'ORD-NULL');

        $ids = collect($this->getJson('/api/orders')->assertOk()->json('data.data'))->pluck('id');

        $this->assertTrue($ids->contains($own->id));
        $this->assertCount(1, $ids);
    }

    public function test_seller_cannot_widen_with_seller_id_filter(): void
    {
        $sellerA = $this->actingAsRole('seller');
        $sellerB = User::factory()->create();
        $sellerB->assignRole('seller');
        $this->makeOrder($sellerB->id, 'ORD-B');

        $ids = collect(
            $this->getJson('/api/orders?seller_id='.$sellerB->id)->assertOk()->json('data.data')
        )->pluck('id');

        $this->assertCount(0, $ids);
    }

    public function test_group_leader_lists_group_orders_and_own(): void
    {
        $group = SalesGroup::create(['name' => 'eBay A', 'platform' => 'ebay', 'status' => true]);
        $other = SalesGroup::create(['name' => 'eBay B', 'platform' => 'ebay', 'status' => true]);

        $leader = $this->actingAsRole('group_leader', ['sales_group_id' => $group->id]);
        $sellerIn = User::factory()->create(['sales_group_id' => $group->id]);
        $sellerIn->assignRole('seller');
        $sellerOut = User::factory()->create(['sales_group_id' => $other->id]);
        $sellerOut->assignRole('seller');

        $own = $this->makeOrder($leader->id, 'ORD-L');
        $inGroup = $this->makeOrder($sellerIn->id, 'ORD-IN');
        $this->makeOrder($sellerOut->id, 'ORD-OUT');

        $ids = collect($this->getJson('/api/orders')->assertOk()->json('data.data'))->pluck('id');

        $this->assertTrue($ids->contains($own->id));
        $this->assertTrue($ids->contains($inGroup->id));
        $this->assertCount(2, $ids);
    }

    public function test_null_group_leader_sees_own_orders_only(): void
    {
        $leader = $this->actingAsRole('group_leader', ['sales_group_id' => null]);
        $leader->assignRole('seller');

        $own = $this->makeOrder($leader->id, 'ORD-OWN');
        $other = User::factory()->create();
        $other->assignRole('seller');
        $this->makeOrder($other->id, 'ORD-OTHER');

        $ids = collect($this->getJson('/api/orders')->assertOk()->json('data.data'))->pluck('id');

        $this->assertTrue($ids->contains($own->id));
        $this->assertCount(1, $ids);
    }

    public function test_admin_sees_all_including_null_seller(): void
    {
        $this->actingAsRole('admin');
        $seller = User::factory()->create();
        $seller->assignRole('seller');

        $a = $this->makeOrder($seller->id, 'ORD-S');
        $n = $this->makeOrder(null, 'ORD-N');

        $ids = collect($this->getJson('/api/orders')->assertOk()->json('data.data'))->pluck('id');

        $this->assertTrue($ids->contains($a->id));
        $this->assertTrue($ids->contains($n->id));
    }

    public function test_seller_show_foreign_order_is_404(): void
    {
        $sellerA = $this->actingAsRole('seller');
        $sellerB = User::factory()->create();
        $sellerB->assignRole('seller');
        $foreign = $this->makeOrder($sellerB->id, 'ORD-B');

        $this->getJson('/api/orders/'.$foreign->id)->assertNotFound();
        $this->assertNotNull($sellerA->id);
    }

    public function test_roleless_user_sees_nothing(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $seller = User::factory()->create();
        $seller->assignRole('seller');
        $order = $this->makeOrder($seller->id, 'ORD-S');
        $this->makeOrder($user->id, 'ORD-SELF');

        $this->getJson('/api/orders')->assertOk()->assertJsonPath('data.data', []);
        $this->getJson('/api/orders/'.$order->id)->assertNotFound();
    }

    public function test_null_seller_order_hidden_from_seller(): void
    {
        $this->actingAsRole('seller');
        $order = $this->makeOrder(null, 'ORD-NULL');

        $this->getJson('/api/orders')->assertOk()->assertJsonPath('data.data', []);
        $this->getJson('/api/orders/'.$order->id)->assertNotFound();
    }

    public function test_seller_cannot_reassign_seller_id(): void
    {
        $sellerA = $this->actingAsRole('seller');
        $sellerB = User::factory()->create();
        $sellerB->assignRole('seller');
        $order = $this->makeOrder($sellerA->id, 'ORD-A');

        $this->putJson('/api/orders/'.$order->id, ['seller_id' => $sellerB->id])
            ->assertUnprocessable()
            ->assertJsonPath('data.seller_id.0', 'Bạn không được thay đổi seller của đơn hàng.');

        $this->assertSame($sellerA->id, $order->fresh()->seller_id);
    }

    public function test_printify_preview_foreign_order_is_404_before_shop_checks(): void
    {
        $account = PrintifyAccount::create([
            'email' => 'a@example.com',
            'api_key' => 'key',
            'is_active' => true,
        ]);
        $shop = PrintifyShop::create([
            'printify_account_id' => $account->id,
            'printify_shop_id' => 101,
            'title' => 'Shop',
            'is_active' => true,
            'is_open' => true,
            'default_sku' => 'SKU',
            'orders_sync_state' => 'complete',
            'manual_approval_confirmed_at' => now(),
        ]);

        $sellerA = User::factory()->create();
        $sellerA->printifyShops()->attach($shop->id, ['is_default' => true]);
        $sellerA->assignRole('seller');
        Sanctum::actingAs($sellerA);

        $sellerB = User::factory()->create();
        $sellerB->assignRole('seller');
        $foreign = $this->makeOrder($sellerB->id, 'ORD-B');

        $this->postJson('/api/orders/'.$foreign->id.'/printify-preview')
            ->assertNotFound();
    }
}
