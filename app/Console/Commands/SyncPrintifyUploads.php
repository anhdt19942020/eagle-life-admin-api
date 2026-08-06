<?php

namespace App\Console\Commands;

use App\Models\PrintifyAccount;
use App\Services\Printify\PrintifySyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncPrintifyUploads extends Command
{
    protected $signature = 'printify:sync-uploads {--limit-pages=}';

    protected $description = 'Sync Printify uploads for every active account (sequential)';

    public function handle(PrintifySyncService $sync): int
    {
        $limitPages = $this->option('limit-pages') !== null && $this->option('limit-pages') !== ''
            ? (int) $this->option('limit-pages')
            : null;

        $accounts = PrintifyAccount::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($accounts->isEmpty()) {
            $this->warn('No active Printify accounts to sync uploads.');

            return self::SUCCESS;
        }

        $synced = 0;
        $failed = 0;

        foreach ($accounts as $account) {
            try {
                $count = $sync->syncUploads($account, $limitPages);
                $synced += $count;
                $this->info("Account {$account->id} ({$account->email}): synced {$count} upload(s).");
            } catch (Throwable $exception) {
                $failed++;
                $this->error("Account {$account->id} ({$account->email}): {$exception->getMessage()}");
            }
        }

        $this->info("Done. uploads={$synced} accounts_failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
