<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Trust the proxy in front of the app (Laravel Cloud's edge, or EnvKit's
        // share tunnel locally) so URLs keep the https scheme and public host.
        $middleware->trustProxies(at: '*');
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (PixelforgeException $exception, Request $request) {
            $message = 'The image provider is unavailable right now. Please try again in a moment.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 503);
            }

            return back()->withErrors(['generation' => $message]);
        });
    })->create();
