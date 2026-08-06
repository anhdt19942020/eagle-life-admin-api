<?php

namespace App\Console\Commands;

use App\Jobs\EnsurePrintifyAccountDefaultSkusJob;
use App\Models\PrintifyAccount;
use App\Services\Printify\PrintifySyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncPrintifyShops extends Command
{
    protected $signature = 'printify:sync-shops';

    protected $description = 'Sync Printify shops for every active account (sequential)';

    public function handle(PrintifySyncService $sync): int
    {
        $accounts = PrintifyAccount::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($accounts->isEmpty()) {
            $this->warn('No active Printify accounts to sync.');

            return self::SUCCESS;
        }

        $synced = 0;
        $failed = 0;

        foreach ($accounts as $account) {
            try {
                $count = $sync->syncShops($account);
                $synced += $count;
                EnsurePrintifyAccountDefaultSkusJob::dispatch($account->id);
                $this->info("Account {$account->id} ({$account->email}): synced {$count} shop(s); default-SKU ensure queued.");
            } catch (Throwable $exception) {
                $failed++;
                $this->error("Account {$account->id} ({$account->email}): {$exception->getMessage()}");
            }
        }

        $this->info("Done. shops_upserted={$synced} accounts_failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
