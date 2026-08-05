<?php

// db-refresh-allow: feature tests use isolated sqlite via DatabaseMigrations (existing project pattern)

namespace Tests\Feature;

use App\Models\PrintifyShop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PrintifyShopSyncApiTest extends TestCase
{
    use DatabaseMigrations;

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
        $this->postJson('/api/printify/shops/sync')->assertForbidden();
    }

    public function test_sync_upserts_by_printify_shop_id_without_duplicates(): void
    {
        $this->actingSyncer();
        config()->set('services.printify.token', 'test-pat');
        config()->set('services.printify.base_url', 'https://printify.test/v1');

        $existing = PrintifyShop::create([
            'printify_shop_id' => 101,
            'title' => 'Old Name',
            'is_active' => true,
            'is_open' => false,
        ]);

        Http::fake([
            'printify.test/v1/shops.json' => Http::response([
                ['id' => 101, 'title' => 'Renamed Shop'],
                ['id' => 202, 'title' => 'Brand New'],
            ]),
        ]);

        $this->postJson('/api/printify/shops/sync')
            ->assertOk()
            ->assertJsonPath('data.synced', 2);

        $this->assertSame(2, PrintifyShop::count());
        $this->assertSame(1, PrintifyShop::where('printify_shop_id', 101)->count());
        $this->assertDatabaseHas('printify_shops', [
            'id' => $existing->id,
            'printify_shop_id' => 101,
            'title' => 'Renamed Shop',
            'is_open' => false,
        ]);
        $this->assertDatabaseHas('printify_shops', [
            'printify_shop_id' => 202,
            'title' => 'Brand New',
            'is_active' => true,
        ]);
    }
}
