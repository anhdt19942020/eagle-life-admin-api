<?php

// db-refresh-allow: feature tests use isolated sqlite via DatabaseMigrations (existing project pattern)

namespace Tests\Feature;

use App\Models\PrintifyShop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\InteractsWithPrintifyAccounts;
use Tests\TestCase;

class PrintifyShopSyncApiTest extends TestCase
{
    use DatabaseMigrations;
    use InteractsWithPrintifyAccounts;

    private function actingSyncer(): User
    {
        $user = User::factory()->create();
        Permission::findOrCreate('printify.sync', 'api');
        $user->givePermissionTo('printify.sync');
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_sync_endpoint_requires_printify_sync_permission(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/printify/shops/sync', ['account_id' => 1])->assertForbidden();
    }

    public function test_sync_requires_account_id(): void
    {
        $this->actingSyncer();

        $this->postJson('/api/printify/shops/sync')
            ->assertUnprocessable();
    }

    public function test_sync_upserts_by_printify_shop_id_without_duplicates(): void
    {
        $this->actingSyncer();
        $this->configurePrintifyHttpBase();
        $account = $this->makePrintifyAccount();

        $existing = $this->makePrintifyShop($account, [
            'printify_shop_id' => 101,
            'title' => 'Old Name',
            'is_open' => false,
        ]);

        Http::fake([
            'printify.test/v1/shops.json' => Http::response([
                ['id' => 101, 'title' => 'Renamed Shop'],
                ['id' => 202, 'title' => 'Brand New'],
            ]),
        ]);

        $this->postJson('/api/printify/shops/sync', ['account_id' => $account->id])
            ->assertOk()
            ->assertJsonPath('data.synced', 2)
            ->assertJsonPath('data.account_id', $account->id);

        $this->assertSame(2, PrintifyShop::count());
        $this->assertSame(1, PrintifyShop::where('printify_shop_id', 101)->count());
        $this->assertDatabaseHas('printify_shops', [
            'id' => $existing->id,
            'printify_shop_id' => 101,
            'printify_account_id' => $account->id,
            'title' => 'Renamed Shop',
            'is_open' => false,
        ]);
        $this->assertDatabaseHas('printify_shops', [
            'printify_shop_id' => 202,
            'printify_account_id' => $account->id,
            'title' => 'Brand New',
            'is_active' => true,
        ]);
    }

    public function test_sync_aborts_when_remote_shop_belongs_to_another_account(): void
    {
        $this->actingSyncer();
        $this->configurePrintifyHttpBase();
        $accountA = $this->makePrintifyAccount('a@example.com', 'token-a');
        $accountB = $this->makePrintifyAccount('b@example.com', 'token-b');
        $this->makePrintifyShop($accountB, [
            'printify_shop_id' => 101,
            'title' => 'Owned by B',
        ]);

        Http::fake([
            'printify.test/v1/shops.json' => Http::response([
                ['id' => 101, 'title' => 'Hijack attempt'],
            ]),
        ]);

        $this->postJson('/api/printify/shops/sync', ['account_id' => $accountA->id])
            ->assertStatus(409);

        $this->assertDatabaseHas('printify_shops', [
            'printify_shop_id' => 101,
            'printify_account_id' => $accountB->id,
            'title' => 'Owned by B',
        ]);
        $this->assertSame(1, PrintifyShop::count());
    }
}
