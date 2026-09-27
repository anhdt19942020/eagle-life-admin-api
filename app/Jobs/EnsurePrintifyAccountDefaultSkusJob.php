<?php

namespace App\Jobs;

use App\Models\PrintifyAccount;
use App\Services\Printify\PrintifyDefaultSkuEnsurer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class EnsurePrintifyAccountDefaultSkusJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;
    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return (string) $this->accountId;
    }


    public function __construct(public readonly int $accountId) {}

    public function handle(PrintifyDefaultSkuEnsurer $ensurer): void
    {
        $account = PrintifyAccount::query()
            ->whereKey($this->accountId)
            ->where('is_active', true)
            ->first();

        if ($account === null) {
            Log::warning('printify_default_sku.job_skipped', [
                'account_id' => $this->accountId,
                'reason' => 'inactive_or_missing',
            ]);

            return;
        }

        $stats = $ensurer->ensureForAccount($account, seedProduct: true, dryRun: false);

        Log::info('printify_default_sku.job_done', [
            'account_id' => $account->id,
            'set' => $stats['set'],
            'skipped' => $stats['skipped'],
            'failed' => $stats['failed'],
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('printify_default_sku.job_failed', [
            'account_id' => $this->accountId,
            'exception_class' => $exception !== null ? $exception::class : null,
            'message' => $exception?->getMessage(),
        ]);
    }
}
