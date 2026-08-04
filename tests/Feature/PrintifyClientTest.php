<?php

namespace Tests\Feature;

use App\Services\Printify\PrintifyClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PrintifyClientTest extends TestCase
{
    public function test_it_sends_pat_as_bearer_token_without_exposing_it(): void
    {
        config()->set('services.printify.token', 'test-pat');
        config()->set('services.printify.base_url', 'https://printify.test/v1');
        Http::fake(['printify.test/*' => Http::response(['data' => []])]);

        $result = app(PrintifyClient::class)->get('/shops.json');

        $this->assertSame(['data' => []], $result);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-pat'));
    }

    public function test_it_retries_a_rate_limited_request(): void
    {
        config()->set('services.printify.token', 'test-pat');
        config()->set('services.printify.base_url', 'https://printify.test/v1');
        config()->set('services.printify.retry_times', 2);
        config()->set('services.printify.retry_sleep_ms', 0);
        Http::fake(['printify.test/*' => Http::sequence()->push(['error' => 'rate limited'], 429)->push(['data' => []], 200)]);

        $this->assertSame(['data' => []], app(PrintifyClient::class)->get('/shops.json'));
        Http::assertSentCount(2);
    }
}
