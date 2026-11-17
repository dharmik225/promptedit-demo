<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Services\Pixelforge\PixelforgeWebhookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessPixelforgeWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 30, 60, 300];

    public function __construct(public WebhookEvent $webhookEvent) {}

    public function handle(PixelforgeWebhookService $webhookService): void
    {
        $webhookService->process($this->webhookEvent);
    }

    public function failed(?Throwable $exception): void
    {
        app(PixelforgeWebhookService::class)->markFailed(
            $this->webhookEvent,
            $exception?->getMessage() ?? 'Webhook processing failed.',
        );
    }
}
