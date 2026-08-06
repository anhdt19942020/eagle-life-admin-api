<?php

namespace App\Console\Commands;

use App\Models\PrintifyAccount;
use App\Models\PrintifyShop;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Throwable;

class BootstrapLegacyPrintifyAccount extends Command
{
    private const LEGACY_ACCOUNT_ID = 1;

    protected $signature = 'printify:bootstrap-account
        {--email= : Account email; falls back to PRINTIFY_ACCOUNT_EMAIL}
        {--force : Replace an already-configured account 1 email/API key}';

    protected $description = 'Idempotently create account 1 from the legacy PRINTIFY_TOKEN and attach every existing shop to it';

    public function handle(): int
    {
        $email = (string) ($this->option('email') ?: config('services.printify.account_email'));
        $token = (string) config('services.printify.token');

        if ($email === '' || $token === '') {
            $this->error('Missing PRINTIFY_ACCOUNT_EMAIL (or --email) and/or PRINTIFY_TOKEN. Aborting.');

            return self::FAILURE;
        }

        $existing = PrintifyAccount::find(self::LEGACY_ACCOUNT_ID);

        try {
            $needsReplace = $existing !== null && ($existing->email !== $email || $existing->api_key !== $token);
        } catch (DecryptException) {
            $this->error('Account 1 exists but its stored API key cannot be decrypted under the current or previous APP_KEY. Restore the correct key (see APP_PREVIOUS_KEYS) or pass --force to overwrite it.');

            return self::FAILURE;
        }

        if ($needsReplace && ! $this->option('force')) {
            $this->error('Account 1 is already configured with a different email or API key. Pass --force to replace it.');

            return self::FAILURE;
        }

        try {
            $shopsBackfilled = DB::transaction(function () use ($existing, $email, $token) {
                $account = $existing ?? new PrintifyAccount;
                $account->id = self::LEGACY_ACCOUNT_ID;
                $account->email = $email;
                $account->api_key = $token;
                if ($existing === null) {
                    $account->is_active = true;
                }
                $account->save();

                return PrintifyShop::whereNull('printify_account_id')
                    ->update(['printify_account_id' => $account->id]);
            });
        } catch (Throwable) {
            // Never surface $e->getMessage() here: a QueryException interpolates bound values
            // (including the plaintext token) directly into its message text.
            $this->error('Failed to save account 1. Check application logs for a non-secret error code; no credential is included in this message.');

            return self::FAILURE;
        }

        $totalShops = PrintifyShop::where('printify_account_id', self::LEGACY_ACCOUNT_ID)->count();

        $this->info("Account 1 ready (email={$email}). Backfilled {$shopsBackfilled} shop(s); account 1 now owns {$totalShops} shop(s) total.");

        return self::SUCCESS;
    }
}
