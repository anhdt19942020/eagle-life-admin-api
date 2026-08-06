<?php

// db-refresh-allow: feature tests use isolated sqlite via DatabaseMigrations (existing project pattern)

namespace Tests\Feature;

use App\Jobs\SyncPrintifyShopsJob;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PrintifyAccountStoreSyncTest extends TestCase
{
    use DatabaseMigrations;

    private function actingManager(): User
    {
        $user = User::factory()->create();
        Permission::findOrCreate('printify.accounts.manage', 'api');
        $user->givePermissionTo('printify.accounts.manage');
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_creating_account_queues_shop_sync_after_response(): void
    {
        Bus::fake();
        $this->actingManager();

        $response = $this->postJson('/api/printify/accounts', [
            'email' => 'new-account@example.com',
            'api_key' => 'pat-secret-value',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.email', 'new-account@example.com')
            ->assertJsonFragment([
                'message' => 'Tạo Printify account thành công. Hệ thống đang đồng bộ shop — có thể mất vài phút, vui lòng làm mới danh sách shop sau.',
            ]);

        $accountId = (int) $response->json('data.id');
        $this->assertGreaterThan(0, $accountId);

        Bus::assertDispatchedAfterResponse(
            SyncPrintifyShopsJob::class,
            fn (SyncPrintifyShopsJob $job) => $job->accountId === $accountId
        );
    }
}
