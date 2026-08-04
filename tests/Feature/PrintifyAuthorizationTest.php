<?php

namespace Tests\Feature;

use App\Models\PrintifyShop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PrintifyAuthorizationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_catalog_routes_require_authentication_and_permission(): void
    {
        $this->getJson('/api/printify/shops')->assertUnauthorized();

        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->getJson('/api/printify/shops')->assertForbidden();

        Permission::create(['name' => 'printify.catalog.view', 'guard_name' => 'api']);
        $user->givePermissionTo('printify.catalog.view');
        $this->getJson('/api/printify/shops')->assertOk();
    }
}
