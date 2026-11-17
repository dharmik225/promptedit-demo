<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

class PixelforgeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public static function fromResponse(Response $response): self
    {
        $error = $response->json('error');
        $error = is_array($error) ? $error : [];

        $retryAfter = $response->header('Retry-After');

        return new self(
            message: (string) ($error['message'] ?? "Pixelforge responded with HTTP {$response->status()}."),
            status: $response->status(),
            errorCode: isset($error['code']) ? (string) $error['code'] : null,
            retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }

    public static function fromConnectionFailure(ConnectionException $exception): self
    {
        return new self(
            message: 'Could not reach Pixelforge.',
            errorCode: 'connection_failed',
            previous: $exception,
        );
    }

    public static function locallyRateLimited(int $retryAfter): self
    {
        return new self(
            message: 'Pixelforge rate limit is cooling down.',
            status: 429,
            errorCode: 'rate_limit_exceeded',
            retryAfter: $retryAfter,
        );
    }

    /**
     * Network failures, rate/concurrency limits and 5xx responses are temporary.
     * The daily quota only resets at midnight UTC, so retrying it is pointless.
     */
    public function isRetryable(): bool
    {
        if ($this->status === null) {
            return true;
        }

        if ($this->status === 429) {
            return $this->errorCode !== 'daily_limit_exceeded';
        }

        return $this->status >= 500;
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    public function isRequestRateLimited(): bool
    {
        return $this->errorCode === 'rate_limit_exceeded';
    }
}
