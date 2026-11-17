<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\WebhookEventStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\PixelforgeWebhookRequest;
use App\Jobs\ProcessPixelforgeWebhook;
use App\Services\Pixelforge\PixelforgeWebhookService;
use Illuminate\Http\Response;

class PixelforgeWebhookController extends Controller
{
    /**
     * Acknowledge once the event is safely stored; the slow work runs in a
     * queued job. A duplicate delivery of an event that is still pending is
     * re-queued in case the first job was lost; processing is idempotent.
     */
    public function __invoke(PixelforgeWebhookRequest $request, PixelforgeWebhookService $webhookService): Response
    {
        $webhookEvent = $webhookService->record($request->all(), $request->deliveryAttempt());

        if ($webhookEvent->wasRecentlyCreated || $webhookEvent->processing_status === WebhookEventStatus::Pending) {
            ProcessPixelforgeWebhook::dispatch($webhookEvent);
        }

        return response()->noContent();
    }
}
