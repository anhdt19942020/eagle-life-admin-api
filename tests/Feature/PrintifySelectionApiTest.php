<?php

// db-refresh-allow: isolated sqlite DatabaseMigrations

namespace Tests\Feature;

use App\Models\PrintifyProduct;
use App\Models\PrintifyProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\InteractsWithPrintifyAccounts;
use Tests\TestCase;

class PrintifySelectionApiTest extends TestCase
{
    use DatabaseMigrations;
    use InteractsWithPrintifyAccounts;

    public function test_products_are_scoped_to_selected_shop_and_use_allowlisted_fields(): void
    {
        $user = User::factory()->create();
        Role::findOrCreate('admin', 'api');
        $user->assignRole('admin');
        Permission::findOrCreate('printify.catalog.view', 'api');
        $user->givePermissionTo('printify.catalog.view');
        Sanctum::actingAs($user);

        $account = $this->makePrintifyAccount();
        $selectedShop = $this->makePrintifyShop($account, ['printify_shop_id' => 101, 'title' => 'Selected']);
        $otherShop = $this->makePrintifyShop($account, ['printify_shop_id' => 102, 'title' => 'Other']);
        $product = PrintifyProduct::create(['printify_shop_id' => $selectedShop->id, 'printify_product_id' => 'p-1', 'title' => 'Selected product']);
        PrintifyProduct::create(['printify_shop_id' => $otherShop->id, 'printify_product_id' => 'p-2', 'title' => 'Other product']);
        PrintifyProductVariant::create(['printify_product_id' => $product->id, 'printify_variant_id' => 'v-1', 'title' => 'Medium']);

        $response = $this->getJson("/api/printify/products?shop_id={$selectedShop->id}");

        $response->assertOk()
            ->assertJsonPath('data.0.title', 'Selected product')
            ->assertJsonMissing(['printify_shop_id' => $selectedShop->id])
            ->assertJsonMissing(['token' => 'test-pat']);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_products_require_a_selected_shop(): void
    {
        $user = User::factory()->create();
        Role::findOrCreate('admin', 'api');
        $user->assignRole('admin');
        Permission::findOrCreate('printify.catalog.view', 'api');
        $user->givePermissionTo('printify.catalog.view');
        Sanctum::actingAs($user);

        $this->getJson('/api/printify/products')->assertUnprocessable();
    }
}
