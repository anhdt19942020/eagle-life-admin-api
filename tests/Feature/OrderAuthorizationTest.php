<?php

namespace Tests\Feature;

use App\Models\User;
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
}
