<?php

// db-refresh-allow: matches existing Feature suite; phpunit uses isolated sqlite :memory: via DatabaseMigrations

namespace Tests\Feature;

use App\Models\PrintifyAccount;
use App\Models\PrintifyShop;
use App\Models\SalesGroup;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserRoleGroupValidationTest extends TestCase
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

    public function test_seller_without_sales_group_is_rejected(): void
    {
        $this->actingAdmin();

        $this->postJson('/api/users', [
            'name' => 'Seller One',
            'username' => 'seller1',
            'email' => 'seller1@example.com',
            'password' => 'password123',
            'role' => 'seller',
        ])->assertUnprocessable()
            ->assertJsonPath('data.sales_group_id.0', 'The sales group id field is required.');
    }

    public function test_create_user_without_username_is_rejected(): void
    {
        $this->actingAdmin();

        $this->postJson('/api/users', [
            'name' => 'Thảo Huyền',
            'password' => '12345678',
            'role' => 'seller',
            'email' => 'thaohuyen@gmail.com',
            'phone' => '0123456789',
        ])->assertUnprocessable()
            ->assertJsonPath('data.username.0', 'The username field is required.')
            ->assertJsonMissingPath('errors');
    }

    public function test_seller_with_sales_group_is_created(): void
    {
        $this->actingAdmin();

        $group = SalesGroup::create([
            'name' => 'eBay A',
            'platform' => 'ebay',
            'status' => true,
        ]);

        $account = PrintifyAccount::create([
            'email' => 'account@example.com',
            'api_key' => 'test-key',
            'is_active' => true,
        ]);
        $shop = PrintifyShop::create([
            'printify_account_id' => $account->id,
            'printify_shop_id' => 999001,
            'title' => 'Shop One',
            'is_active' => true,
        ]);

        $this->postJson('/api/users', [
            'name' => 'Seller One',
            'username' => 'seller1',
            'email' => 'seller1@example.com',
            'password' => 'password123',
            'role' => 'seller',
            'sales_group_id' => $group->id,
            'printify_shop_ids' => [$shop->id],
            'default_printify_shop_id' => $shop->id,
        ])->assertCreated()
            ->assertJsonPath('data.sales_group_id', $group->id)
            ->assertJsonPath('data.roles.0.name', 'seller')
            ->assertJsonPath('data.printify_shops.0.id', $shop->id)
            ->assertJsonPath('data.printify_shops.0.pivot.is_default', true);
    }

    public function test_admin_user_clears_sales_group(): void
    {
        $this->actingAdmin();

        $group = SalesGroup::create([
            'name' => 'eBay A',
            'platform' => 'ebay',
            'status' => true,
        ]);

        $this->postJson('/api/users', [
            'name' => 'Admin Two',
            'username' => 'admin2',
            'email' => 'admin2@example.com',
            'password' => 'password123',
            'role' => 'admin',
            'sales_group_id' => $group->id,
        ])->assertCreated()
            ->assertJsonPath('data.sales_group_id', null)
            ->assertJsonPath('data.roles.0.name', 'admin');
    }

    public function test_seller_forbidden_on_users_and_roles(): void
    {
        $group = SalesGroup::create([
            'name' => 'eBay A',
            'platform' => 'ebay',
            'status' => true,
        ]);

        $seller = User::factory()->create(['sales_group_id' => $group->id]);
        $seller->assignRole('seller');
        Sanctum::actingAs($seller);

        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/roles')->assertForbidden();
    }

    public function test_admin_can_list_roles(): void
    {
        $this->actingAdmin();

        $this->getJson('/api/roles')->assertOk()
            ->assertJsonPath('data.0.name', 'admin');

        $names = collect($this->getJson('/api/roles')->json('data'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['admin', 'seller', 'group_leader'], $names);
        $this->assertFalse(Role::whereIn('name', ['manager', 'sale', 'buyer'])->exists());
    }

    public function test_seeder_migrates_legacy_sale_role(): void
    {
        $user = User::factory()->create();
        $legacy = Role::create(['name' => 'sale', 'guard_name' => 'api']);
        $user->assignRole($legacy);

        $this->seed(RolePermissionSeeder::class);

        $user->refresh();
        $this->assertTrue($user->hasRole('seller'));
        $this->assertFalse($user->hasRole('sale'));
        $this->assertFalse(Role::where('name', 'sale')->exists());
    }
}
