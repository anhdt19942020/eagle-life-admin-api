<?php

namespace Tests\Support;

use App\Models\PrintifyAccount;
use App\Models\PrintifyShop;
use App\Models\User;
use App\Services\Printify\PrintifyClient;
use App\Services\Printify\PrintifyClientFactory;
use Illuminate\Http\Client\Factory as HttpFactory;

trait InteractsWithPrintifyAccounts
{
    protected function makePrintifyAccount(string $email = 'account@example.com', string $apiKey = 'test-pat', bool $active = true): PrintifyAccount
    {
        return PrintifyAccount::create([
            'email' => $email,
            'api_key' => $apiKey,
            'is_active' => $active,
        ]);
    }

    protected function makePrintifyShop(PrintifyAccount $account, array $attributes = []): PrintifyShop
    {
        return PrintifyShop::create(array_merge([
            'printify_account_id' => $account->id,
            'printify_shop_id' => 101,
            'title' => 'Primary',
            'is_active' => true,
            'is_open' => true,
        ], $attributes));
    }

    protected function makeSellerForShop(PrintifyShop $shop): User
    {
        $user = User::factory()->create();
        $user->printifyShops()->attach($shop->id, ['is_default' => true]);

        return $user;
    }

    protected function configurePrintifyHttpBase(): void
    {
        config()->set('services.printify.base_url', 'https://printify.test/v1');
        config()->set('services.printify.retry_times', 0);
        config()->set('services.printify.retry_sleep_ms', 0);
    }

    protected function clientFor(PrintifyAccount $account): PrintifyClient
    {
        return app(PrintifyClientFactory::class)->for($account);
    }

    protected function clientWithToken(string $token): PrintifyClient
    {
        return new PrintifyClient(app(HttpFactory::class), $token);
    }
}
