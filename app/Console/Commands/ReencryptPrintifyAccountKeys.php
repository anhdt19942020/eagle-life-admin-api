<?php

namespace App\Console\Commands;

use App\Models\PrintifyAccount;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;

class ReencryptPrintifyAccountKeys extends Command
{
    protected $signature = 'printify:reencrypt-keys';

    protected $description = 'Re-encrypt every printify_accounts.api_key under the current APP_KEY. Run while the prior key is still listed in APP_PREVIOUS_KEYS.';

    public function handle(): int
    {
        $reencrypted = [];
        $failed = [];

        DB::transaction(function () use (&$reencrypted, &$failed) {
            foreach (PrintifyAccount::query()->get() as $account) {
                try {
                    // Decrypts via the current key (falling back to APP_PREVIOUS_KEYS) and
                    // re-encrypts under the current key in memory only — this does not persist.
                    $account->api_key = $account->api_key;
                } catch (DecryptException) {
                    $failed[] = $account->id;

                    continue;
                }

                // save() cannot be used here: Eloquent's dirty-check decrypts both the current and
                // original 'encrypted'-cast values and compares plaintexts (HasAttributes::
                // originalIsEquivalent()), so a same-plaintext reassignment is never seen as dirty
                // and save() silently skips the UPDATE. Write the freshly re-encrypted ciphertext
                // directly to bypass that check.
                DB::table('printify_accounts')
                    ->where('id', $account->id)
                    ->update(['api_key' => $account->getAttributes()['api_key']]);
                $reencrypted[] = $account->id;
            }
        });

        if ($failed !== []) {
            $this->error('Could not decrypt account(s): '.implode(', ', $failed).' — neither APP_KEY nor APP_PREVIOUS_KEYS could read them.');
        }

        $this->info('Re-encrypted '.count($reencrypted).' account(s): '.implode(', ', $reencrypted));

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
