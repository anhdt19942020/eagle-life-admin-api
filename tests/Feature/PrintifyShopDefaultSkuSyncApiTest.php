<?php

// db-refresh-allow: isolated sqlite DatabaseMigrations

namespace Tests\Feature;

use App\Models\PrintifyOrder;
use App\Models\PrintifyProduct;
use App\Models\PrintifyProductVariant;
use App\Models\PrintifyShop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\InteractsWithPrintifyAccounts;
use Tests\TestCase;

class PrintifyShopDefaultSkuSyncApiTest extends TestCase
{
    use DatabaseMigrations;
    use InteractsWithPrintifyAccounts;

    private function actingAdminConfirm(): User
    {
        $user = User::factory()->create();
        Role::findOrCreate('admin', 'api');
        $user->assignRole('admin');
        Permission::findOrCreate('printify.shop-readiness.confirm', 'api');
        $user->givePermissionTo('printify.shop-readiness.confirm');
        Sanctum::actingAs($user);

        return $user;
    }

    private function actingLeaderForShop(PrintifyShop $shop): User
    {
        $user = User::factory()->create(['printify_shop_id' => $shop->id]);
        Role::findOrCreate('group_leader', 'api');
        $user->assignRole('group_leader');
        Permission::findOrCreate('printify.shop-readiness.confirm', 'api');
        $user->givePermissionTo('printify.shop-readiness.confirm');
        Sanctum::actingAs($user);

        return $user;
    }

    private function shopNeedingSku(array $overrides = []): PrintifyShop
    {
        $account = $this->makePrintifyAccount();

        return $this->makePrintifyShop($account, array_merge([
            'printify_shop_id' => 601,
            'title' => 'Ensure SKU Shop',
            'default_sku' => null,
            'orders_sync_state' => 'complete',
            'manual_approval_confirmed_at' => now(),
        ], $overrides));
    }

    public function test_sets_default_sku_from_local_unique_variant_without_http(): void
    {
        $shop = $this->shopNeedingSku();
        $product = PrintifyProduct::create([
            'printify_shop_id' => $shop->id,
            'printify_product_id' => 'local-prod',
            'title' => 'Local',
        ]);
        PrintifyProductVariant::create([
            'printify_product_id' => $product->id,
            'printify_variant_id' => 901,
            'sku' => 'LOCAL-UNIQUE',
            'title' => 'S',
            'is_enabled' => true,
        ]);

        $this->configurePrintifyHttpBase();
        Http::fake();
        $this->actingAdminConfirm();

        $this->postJson("/api/printify/shops/{$shop->id}/ensure-default-sku")
            ->assertOk()
            ->assertJsonPath('data.result.code', 'default_sku_set')
            ->assertJsonPath('data.result.sku', 'LOCAL-UNIQUE')
            ->assertJsonPath('data.shop.default_sku', 'LOCAL-UNIQUE');

        Http::assertNothingSent();
        $this->assertSame('LOCAL-UNIQUE', $shop->fresh()->default_sku);
    }

    public function test_sets_default_sku_after_one_product_remote_sync(): void
    {
        $shop = $this->shopNeedingSku(['printify_shop_id' => 602]);
        $this->configurePrintifyHttpBase();
        Http::fake([
            'printify.test/v1/shops/602/products.json*' => Http::response([
                'data' => [
                    [
                        'id' => 'remote-p1',
                        'title' => 'Remote Tee',
                        'blueprint_id' => 1,
                        'print_provider_id' => 2,
                        'variants' => [
                            ['id' => 11, 'sku' => 'REMOTE-SKU', 'title' => 'M', 'is_enabled' => true, 'price' => 1000],
                        ],
                    ],
                ],
                'last_page' => 1,
            ]),
        ]);
        $this->actingAdminConfirm();

        $this->postJson("/api/printify/shops/{$shop->id}/ensure-default-sku")
            ->assertOk()
            ->assertJsonPath('data.result.code', 'default_sku_set')
            ->assertJsonPath('data.shop.default_sku', 'REMOTE-SKU');

        $this->assertSame('REMOTE-SKU', $shop->fresh()->default_sku);
        $this->assertSame(1, PrintifyProduct::where('printify_shop_id', $shop->id)->count());
    }

    public function test_idempotent_when_default_sku_already_set(): void
    {
        $shop = $this->shopNeedingSku(['default_sku' => 'KEEP-SKU']);
        $this->configurePrintifyHttpBase();
        Http::fake();
        $this->actingAdminConfirm();

        $this->postJson("/api/printify/shops/{$shop->id}/ensure-default-sku")
            ->assertOk()
            ->assertJsonPath('data.result.code', 'default_sku_already_set')
            ->assertJsonPath('data.shop.default_sku', 'KEEP-SKU');

        Http::assertNothingSent();
        $this->assertSame('KEEP-SKU', $shop->fresh()->default_sku);
    }

    public function test_returns_default_sku_not_resolved_when_no_unique_sku(): void
    {
        $shop = $this->shopNeedingSku(['printify_shop_id' => 603]);
        $this->configurePrintifyHttpBase();
        Http::fake([
            'printify.test/v1/shops/603/products.json*' => Http::response([
                'data' => [
                    [
                        'id' => 'dup-prod',
                        'title' => 'Dup',
                        'blueprint_id' => 1,
                        'print_provider_id' => 2,
                        'variants' => [
                            ['id' => 21, 'sku' => 'DUP', 'title' => 'A', 'is_enabled' => true, 'price' => 1000],
                            ['id' => 22, 'sku' => 'DUP', 'title' => 'B', 'is_enabled' => true, 'price' => 1000],
                        ],
                    ],
                ],
                'last_page' => 1,
            ]),
        ]);
        $this->actingAdminConfirm();

        $this->postJson("/api/printify/shops/{$shop->id}/ensure-default-sku")
            ->assertStatus(422)
            ->assertJsonPath('data.code', 'default_sku_not_resolved');

        $this->assertNull($shop->fresh()->default_sku);
    }

    public function test_rejects_inactive_shop(): void
    {
        $shop = $this->shopNeedingSku(['is_active' => false]);
        $this->configurePrintifyHttpBase();
        Http::fake();
        $this->actingAdminConfirm();

        $this->postJson("/api/printify/shops/{$shop->id}/ensure-default-sku")
            ->assertStatus(422)
            ->assertJsonPath('data.code', 'printify_shop_not_ready');

        Http::assertNothingSent();
    }

    public function test_rejects_inactive_account(): void
    {
        $account = $this->makePrintifyAccount('inactive@example.com', 'pat', false);
        $shop = $this->makePrintifyShop($account, [
            'printify_shop_id' => 604,
            'default_sku' => null,
        ]);
        $this->configurePrintifyHttpBase();
        Http::fake();
        $this->actingAdminConfirm();

        $this->postJson("/api/printify/shops/{$shop->id}/ensure-default-sku")
            ->assertStatus(422)
            ->assertJsonPath('data.code', 'printify_account_inactive');

        Http::assertNothingSent();
    }

    public function test_forbidden_without_readiness_permission(): void
    {
        $shop = $this->shopNeedingSku();
        $user = User::factory()->create();
        Role::findOrCreate('admin', 'api');
        $user->assignRole('admin');
        Sanctum::actingAs($user);

        $this->configurePrintifyHttpBase();
        Http::fake();

        $this->postJson("/api/printify/shops/{$shop->id}/ensure-default-sku")
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_leader_cannot_ensure_other_shop(): void
    {
        $account = $this->makePrintifyAccount('leader-scope@example.com');
        $owned = $this->makePrintifyShop($account, [
            'printify_shop_id' => 605,
            'title' => 'Owned',
            'default_sku' => null,
        ]);
        $other = $this->makePrintifyShop($account, [
            'printify_shop_id' => 606,
            'title' => 'Other',
            'default_sku' => null,
        ]);
        $this->actingLeaderForShop($owned);

        $this->configurePrintifyHttpBase();
        Http::fake();

        $this->postJson("/api/printify/shops/{$other->id}/ensure-default-sku")
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_remote_failure_returns_502_without_leaking_message(): void
    {
        $shop = $this->shopNeedingSku(['printify_shop_id' => 607]);
        $this->configurePrintifyHttpBase();
        Http::fake([
            'printify.test/v1/shops/607/products.json*' => Http::response('upstream exploded', 500),
        ]);
        $this->actingAdminConfirm();

        $response = $this->postJson("/api/printify/shops/{$shop->id}/ensure-default-sku")
            ->assertStatus(502)
            ->assertJsonPath('data.code', 'default_sku_sync_failed');

        $this->assertStringNotContainsString('upstream exploded', (string) $response->getContent());
    }

    public function test_index_includes_readiness_issues_and_ready_flag(): void
    {
        $account = $this->makePrintifyAccount();
        $ready = $this->makePrintifyShop($account, [
            'printify_shop_id' => 701,
            'title' => 'Ready Shop',
            'default_sku' => 'READY-1',
            'orders_sync_state' => 'complete',
            'manual_approval_confirmed_at' => now(),
        ]);
        $blocked = $this->makePrintifyShop($account, [
            'printify_shop_id' => 702,
            'title' => 'Blocked Shop',
            'default_sku' => null,
            'is_open' => false,
            'orders_sync_state' => 'pending',
        ]);
        PrintifyOrder::create([
            'printify_shop_id' => $ready->id,
            'printify_order_id' => 'conf-1',
            'has_conflict' => true,
        ]);

        Permission::findOrCreate('printify.catalog.view', 'api');
        $user = User::factory()->create();
        Role::findOrCreate('admin', 'api');
        $user->assignRole('admin');
        $user->givePermissionTo('printify.catalog.view');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/printify/shops?per_page=100')->assertOk();
        $items = collect($response->json('data.data') ?? $response->json('data'));
        $readyRow = $items->firstWhere('id', $ready->id);
        $blockedRow = $items->firstWhere('id', $blocked->id);

        $this->assertFalse($readyRow['ready_for_creation']);
        $this->assertContains('order_conflicts', $readyRow['readiness_issues']);

        $this->assertFalse($blockedRow['ready_for_creation']);
        $this->assertContains('missing_default_sku', $blockedRow['readiness_issues']);
        $this->assertContains('shop_closed', $blockedRow['readiness_issues']);
    }

    public function test_after_set_sku_shop_may_remain_not_ready_due_to_other_blockers(): void
    {
        $shop = $this->shopNeedingSku([
            'is_open' => false,
            'orders_sync_state' => 'pending',
            'manual_approval_confirmed_at' => null,
        ]);
        $product = PrintifyProduct::create([
            'printify_shop_id' => $shop->id,
            'printify_product_id' => 'still-blocked',
            'title' => 'Local',
        ]);
        PrintifyProductVariant::create([
            'printify_product_id' => $product->id,
            'printify_variant_id' => 902,
            'sku' => 'PARTIAL-READY',
            'title' => 'S',
            'is_enabled' => true,
        ]);

        $this->configurePrintifyHttpBase();
        Http::fake();
        $this->actingAdminConfirm();

        $this->postJson("/api/printify/shops/{$shop->id}/ensure-default-sku")
            ->assertOk()
            ->assertJsonPath('data.shop.default_sku', 'PARTIAL-READY')
            ->assertJsonPath('data.shop.ready_for_creation', false);

        $issues = $this->postJson("/api/printify/shops/{$shop->id}/ensure-default-sku")
            ->assertOk()
            ->json('data.shop.readiness_issues');

        $this->assertContains('shop_closed', $issues);
        $this->assertContains('manual_approval_required', $issues);
        $this->assertContains('orders_sync_incomplete', $issues);
        $this->assertNotContains('missing_default_sku', $issues);
    }
}
