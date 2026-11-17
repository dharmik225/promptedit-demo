<?php

namespace App\Http\Middleware;

use App\Services\Pixelforge\PixelforgeWebhookService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyPixelforgeSignature
{
    public function __construct(private readonly PixelforgeWebhookService $webhookService) {}

    /** The signature covers the raw body, so verify it before anything decodes JSON. */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->webhookService->hasValidSignature($request->getContent(), $request->header('Pixelforge-Signature'))) {
            Log::warning('Rejected Pixelforge webhook with an invalid signature.', [
                'event_id' => $request->header('Pixelforge-Event-Id'),
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid Pixelforge signature.'], 400);
        }

        return $next($request);
    }
}
