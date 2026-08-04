<?php
namespace App\Services\Printify;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use RuntimeException;
class PrintifyClient { public function __construct(private readonly HttpFactory $http) {} public function get(string $path, array $query = []): array { $token = (string) config('services.printify.token'); if ($token === '') { throw new RuntimeException('Printify is not configured.'); } try { return $this->http->baseUrl((string) config('services.printify.base_url'))->acceptJson()->withToken($token)->timeout((int) config('services.printify.timeout'))->retry((int) config('services.printify.retry_times'), (int) config('services.printify.retry_sleep_ms'), fn ($exception) => $exception instanceof RequestException)->get(ltrim($path, '/'), $query)->throw()->json(); } catch (RequestException $exception) { throw new RuntimeException('Printify request failed.', previous: $exception); } } }
