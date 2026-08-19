<?php

namespace App\Services\Printify;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
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
            throw $this->wrap('GET', $path, $exception);
        }
    }

    public function post(string $path, array $payload = []): array
    {
        try {
            return $this->request()->post(ltrim($path, '/'), $payload)->throw()->json();
        } catch (RequestException $exception) {
            throw $this->wrap('POST', $path, $exception);
        }
    }

    /**
     * Log Printify's full error response (status + body) and re-throw with the
     * concrete reason surfaced, so callers see WHY Printify rejected the request
     * instead of an opaque "Printify request failed." (root cause of 3878/3879).
     */
    private function wrap(string $method, string $path, RequestException $exception): RuntimeException
    {
        $status = $exception->response->status();
        $body = $exception->response->body();

        Log::warning('printify.request_failed', [
            'method' => $method,
            'path' => $path,
            'status' => $status,
            'body' => $body,
        ]);

        return new RuntimeException(
            'Printify request failed ('.$status.'): '.$this->extractReason($body),
            previous: $exception,
        );
    }

    /**
     * Pull the human-readable reason out of a Printify error body. Printify uses
     * both `errors.reason` (e.g. "Product ... is missing") and a top-level
     * `message`; fall back to a trimmed raw body when neither is present.
     */
    private function extractReason(string $body): string
    {
        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            $reason = $decoded['errors']['reason'] ?? $decoded['message'] ?? $decoded['error'] ?? null;
            if (is_string($reason) && trim($reason) !== '') {
                return $reason;
            }
        }

        return trim($body) !== '' ? mb_strimwidth($body, 0, 200, '…') : 'unknown error';
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
