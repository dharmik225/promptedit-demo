<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Generation\GenerationStatusService;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeGenerationService;
use App\Services\Pixelforge\PixelforgePricingService;
use App\Services\Pixelforge\PixelforgeWebhookService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PixelforgeClient::class, fn (Application $app): PixelforgeClient => new PixelforgeClient(
            baseUrl: (string) config('services.pixelforge.base_url'),
            apiKey: config('services.pixelforge.api_key'),
            timeout: (int) config('services.pixelforge.timeout'),
            cache: $app['cache.store'],
        ));

        $this->app->scoped(PixelforgePricingService::class, fn (Application $app): PixelforgePricingService => new PixelforgePricingService(
            client: $app->make(PixelforgeClient::class),
            cache: $app['cache.store'],
            cacheTtlSeconds: (int) config('services.pixelforge.pricing_cache_ttl'),
            markupPercentage: (string) config('services.pixelforge.markup_percentage'),
        ));

        $this->app->singleton(PixelforgeWebhookService::class, fn (Application $app): PixelforgeWebhookService => new PixelforgeWebhookService(
            generationService: $app->make(PixelforgeGenerationService::class),
            statusService: $app->make(GenerationStatusService::class),
            webhookSecret: config('services.pixelforge.webhook_secret'),
            toleranceSeconds: (int) config('services.pixelforge.webhook_tolerance'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('viewAdmin', fn (User $user): bool => $user->isAdmin());
    }
}
