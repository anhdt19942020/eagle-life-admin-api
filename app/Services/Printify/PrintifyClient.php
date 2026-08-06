<?php

namespace App\Services\Printify;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;
use RuntimeException;

class PrintifyClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $token,
    ) {
        if ($token === '') {
            throw new InvalidArgumentException('PrintifyClient requires a non-empty account token.');
        }
    }

    public function get(string $path, array $query = []): array
    {
        try {
            return $this->request()->get(ltrim($path, '/'), $query)->throw()->json();
        } catch (RequestException $exception) {
            throw new RuntimeException('Printify request failed.', previous: $exception);
        }
    }

    public function post(string $path, array $payload = []): array
    {
        try {
            return $this->request()->post(ltrim($path, '/'), $payload)->throw()->json();
        } catch (RequestException $exception) {
            throw new RuntimeException('Printify request failed.', previous: $exception);
        }
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl((string) config('services.printify.base_url'))
            ->acceptJson()
            ->withToken($this->token)
            ->timeout((int) config('services.printify.timeout'))
            ->retry(
                (int) config('services.printify.retry_times'),
                (int) config('services.printify.retry_sleep_ms'),
                fn ($exception) => $exception instanceof RequestException
            );
    }
}
