<?php

namespace App\Services\Pixelforge;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PixelforgeClient
{
    private const RATE_LIMIT_CACHE_KEY = 'pixelforge:rate_limited_until';

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeout,
        private readonly CacheRepository $cache,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createGeneration(array $payload, string $idempotencyKey): array
    {
        return $this->send(fn (PendingRequest $request) => $request
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('generations', $payload));
    }

    /** @return array<string, mixed> */
    public function pricing(): array
    {
        return $this->send(fn (PendingRequest $request) => $request->get('pricing'), authenticated: false);
    }

    /**
     * @param  callable(PendingRequest): Response  $callback
     * @return array<string, mixed>
     */
    private function send(callable $callback, bool $authenticated = true): array
    {
        $this->ensureNotRateLimited();

        try {
            $response = $callback($this->request($authenticated));
        } catch (ConnectionException $exception) {
            Log::warning('Pixelforge request could not connect.', ['message' => $exception->getMessage()]);

            throw PixelforgeException::fromConnectionFailure($exception);
        }

        if ($response->failed()) {
            $exception = PixelforgeException::fromResponse($response);

            $this->rememberRateLimit($exception);

            Log::warning('Pixelforge request failed.', [
                'status' => $exception->status,
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new PixelforgeException('Pixelforge returned a response that is not JSON.', $response->status());
        }

        return $body;
    }

    private function request(bool $authenticated): PendingRequest
    {
        $request = Http::baseUrl(rtrim($this->baseUrl, '/').'/')
            ->acceptJson()
            ->asJson()
            ->connectTimeout(10)
            ->timeout($this->timeout)
            ->withUserAgent('PromptEdit/1.0');

        if (! $authenticated) {
            return $request;
        }

        return $request->withToken((string) $this->apiKey);
    }

    /**
     * The per-minute limit is shared by every request on the account, so once
     * Pixelforge says to wait we skip all calls until Retry-After has passed.
     */
    private function ensureNotRateLimited(): void
    {
        $rateLimitedUntil = (int) $this->cache->get(self::RATE_LIMIT_CACHE_KEY, 0);
        $secondsLeft = $rateLimitedUntil - now()->getTimestamp();

        if ($secondsLeft > 0) {
            throw PixelforgeException::locallyRateLimited($secondsLeft);
        }
    }

    private function rememberRateLimit(PixelforgeException $exception): void
    {
        if (! $exception->isRequestRateLimited()) {
            return;
        }

        $retryAfter = max(1, $exception->retryAfter ?? 60);

        $this->cache->put(self::RATE_LIMIT_CACHE_KEY, now()->getTimestamp() + $retryAfter, $retryAfter);
    }
}
