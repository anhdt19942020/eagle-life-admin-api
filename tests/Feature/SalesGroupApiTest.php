<?php

namespace Tests\Feature;

use App\Models\SalesGroup;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesGroupApiTest extends TestCase
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

    private function actingSeller(): User
    {
        $group = SalesGroup::create([
            'name' => 'eBay Team A',
            'platform' => 'ebay',
            'status' => true,
        ]);

        $user = User::factory()->create([
            'sales_group_id' => $group->id,
        ]);
        $user->assignRole('seller');

        Sanctum::actingAs($user);

        return $user;
    }

    public function test_admin_can_crud_sales_groups(): void
    {
        $this->actingAdmin();

        $create = $this->postJson('/api/sales-groups', [
            'name' => 'TikTok Team',
            'platform' => 'tiktok',
            'code' => 'TT01',
        ])->assertCreated()
            ->assertJsonPath('data.platform', 'tiktok');

        $id = $create->json('data.id');

        $this->getJson('/api/sales-groups')->assertOk()
            ->assertJsonPath('data.data.0.name', 'TikTok Team');

        $this->getJson("/api/sales-groups/{$id}")->assertOk()
            ->assertJsonPath('data.code', 'TT01');

        $this->putJson("/api/sales-groups/{$id}", [
            'name' => 'TikTok Team Updated',
        ])->assertOk()
            ->assertJsonPath('data.name', 'TikTok Team Updated');

        $this->deleteJson("/api/sales-groups/{$id}")->assertOk();
        $this->assertDatabaseMissing('sales_groups', ['id' => $id]);
    }

    public function test_seller_forbidden_on_sales_groups(): void
    {
        $this->actingSeller();

        $this->getJson('/api/sales-groups')->assertForbidden();
        $this->postJson('/api/sales-groups', [
            'name' => 'X',
            'platform' => 'ebay',
        ])->assertForbidden();
    }

    public function test_cannot_delete_group_with_members(): void
    {
        $this->actingAdmin();

        $group = SalesGroup::create([
            'name' => 'Amazon Team',
            'platform' => 'amazon',
            'status' => true,
        ]);

        User::factory()->create(['sales_group_id' => $group->id]);

        $this->deleteJson("/api/sales-groups/{$group->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Không thể xoá nhóm đang có thành viên');
    }

    public function test_platform_must_be_valid(): void
    {
        $this->actingAdmin();

        $this->postJson('/api/sales-groups', [
            'name' => 'Bad',
            'platform' => 'shopee',
        ])->assertUnprocessable();
    }
}
