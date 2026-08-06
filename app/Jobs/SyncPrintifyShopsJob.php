<?php

namespace App\Jobs;

use App\Models\PrintifyAccount;
use App\Services\Printify\PrintifySyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncPrintifyShopsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $accountId) {}

    public function handle(PrintifySyncService $sync): void
    {
        $account = PrintifyAccount::query()
            ->whereKey($this->accountId)
            ->where('is_active', true)
            ->first();

        if ($account === null) {
            Log::warning('printify_shops.sync_job_skipped', [
                'account_id' => $this->accountId,
                'reason' => 'inactive_or_missing',
            ]);

            return;
        }

        $count = $sync->syncShops($account);

        Log::info('printify_shops.sync_job_done', [
            'account_id' => $account->id,
            'synced' => $count,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('printify_shops.sync_job_failed', [
            'account_id' => $this->accountId,
            'exception_class' => $exception !== null ? $exception::class : null,
            'message' => $exception?->getMessage(),
        ]);
    }
}
