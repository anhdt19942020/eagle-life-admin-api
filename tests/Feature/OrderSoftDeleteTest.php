<?php

// db-refresh-allow: matches existing Feature suite; phpunit uses isolated sqlite :memory: via DatabaseMigrations

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\OrderImportService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderSoftDeleteTest extends TestCase
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

    public function test_destroy_soft_deletes_and_sets_deleted_by(): void
    {
        $admin = $this->actingAsRole('admin');
        $order = $this->makeOrder($admin->id, 'SOFT-1');

        $this->deleteJson('/api/orders/'.$order->id)
            ->assertOk();

        $this->assertSoftDeleted('orders', ['id' => $order->id]);
        $this->assertSame($admin->id, Order::withTrashed()->findOrFail($order->id)->deleted_by);

        $ids = collect($this->getJson('/api/orders')->assertOk()->json('data.data'))->pluck('id');
        $this->assertFalse($ids->contains($order->id));
        $this->getJson('/api/orders/'.$order->id)->assertNotFound();
    }

    public function test_admin_can_list_trashed_and_seller_cannot(): void
    {
        $seller = $this->actingAsRole('seller');
        $order = $this->makeOrder($seller->id, 'SOFT-2');

        $this->actingAsRole('admin');
        $this->deleteJson('/api/orders/'.$order->id)->assertOk();

        Sanctum::actingAs($seller);
        $this->getJson('/api/orders?trashed=only')->assertForbidden();

        $this->actingAsRole('admin');
        $ids = collect($this->getJson('/api/orders?trashed=only')->assertOk()->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($order->id));
    }

    public function test_admin_can_restore_and_non_admin_cannot(): void
    {
        $seller = $this->actingAsRole('seller');
        $order = $this->makeOrder($seller->id, 'SOFT-3');

        $this->actingAsRole('admin');
        $this->deleteJson('/api/orders/'.$order->id)->assertOk();

        Sanctum::actingAs($seller);
        $this->postJson('/api/orders/'.$order->id.'/restore')->assertForbidden();

        $this->actingAsRole('admin');
        $this->postJson('/api/orders/'.$order->id.'/restore')
            ->assertOk()
            ->assertJsonPath('data.id', $order->id);

        $restored = $order->fresh();
        $this->assertNull($restored->deleted_at);
        $this->assertNull($restored->deleted_by);

        Sanctum::actingAs($seller);
        $ids = collect($this->getJson('/api/orders')->assertOk()->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($order->id));
    }

    public function test_csv_import_revives_soft_deleted_order_same_id(): void
    {
        $importer = User::factory()->create();
        $csv = "Order Number,Sale Date,Item Number,Quantity,Sold For,Shipping And Handling,Total Price,Ship To Name,Ship To Address 1,Ship To City,Ship To Zip,Ship To Country\n"
            ."13-14975-00010,Aug-02-26,123,1,\$10.00,\$0.00,\$10.00,Jane Doe,1 Main St,Austin,78701,US\n";
        $service = app(OrderImportService::class);

        $service->importFromCsv(UploadedFile::fake()->createWithContent('a.csv', $csv), $importer->id);
        $order = Order::where('ebay_order_number', '13-14975-00010')->firstOrFail();
        $originalId = $order->id;

        $order->forceFill(['deleted_by' => $importer->id])->save();
        $order->delete();
        $this->assertSoftDeleted('orders', ['id' => $originalId]);

        $result = $service->importFromCsv(UploadedFile::fake()->createWithContent('b.csv', $csv), $importer->id);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $revived = Order::where('ebay_order_number', '13-14975-00010')->firstOrFail();
        $this->assertSame($originalId, $revived->id);
        $this->assertNull($revived->deleted_at);
        $this->assertNull($revived->deleted_by);
    }
}
