<?php

// db-refresh-allow: isolated sqlite for account-scoped client tests

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithPrintifyAccounts;
use Tests\TestCase;

class PrintifyClientTest extends TestCase
{
    use DatabaseMigrations;
    use InteractsWithPrintifyAccounts;

    public function test_it_sends_pat_as_bearer_token_without_exposing_it(): void
    {
        $this->configurePrintifyHttpBase();
        Http::fake(['printify.test/*' => Http::response(['data' => []])]);

        $account = $this->makePrintifyAccount(apiKey: 'test-pat');
        $result = $this->clientFor($account)->get('/shops.json');

        $this->assertSame(['data' => []], $result);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-pat'));
    }

    public function test_two_accounts_use_distinct_bearer_tokens_without_shared_state(): void
    {
        $this->configurePrintifyHttpBase();
        Http::fake(['printify.test/*' => Http::response(['data' => []])]);

        $accountA = $this->makePrintifyAccount('a@example.com', 'token-a');
        $accountB = $this->makePrintifyAccount('b@example.com', 'token-b');

        $this->clientFor($accountA)->get('/shops.json');
        $this->clientFor($accountB)->get('/shops.json');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer token-a'));
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer token-b'));
    }

    public function test_it_retries_a_rate_limited_request(): void
    {
        $this->configurePrintifyHttpBase();
        config()->set('services.printify.retry_times', 2);
        config()->set('services.printify.retry_sleep_ms', 0);
        Http::fake(['printify.test/*' => Http::sequence()->push(['error' => 'rate limited'], 429)->push(['data' => []], 200)]);

        $this->assertSame(['data' => []], $this->clientWithToken('test-pat')->get('/shops.json'));
        Http::assertSentCount(2);
    }

    public function test_it_posts_json_payload_with_bearer_token(): void
    {
        $this->configurePrintifyHttpBase();
        Http::fake(['printify.test/*' => Http::response(['id' => 'pog-1'], 200)]);

        $result = $this->clientWithToken('test-pat')->post('/shops/101/orders.json', ['external_id' => '13-14975-00010']);

        $this->assertSame(['id' => 'pog-1'], $result);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-pat')
            && $request['external_id'] === '13-14975-00010');
    }
}
