<?php

namespace App\Jobs;

use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Pixelforge image URLs expire 24 hours after completion, so keep our own copy.
 */
class StoreGenerationImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public Generation $generation) {}

    public function handle(): void
    {
        $generation = $this->generation->fresh();

        if (! $generation) {
            return;
        }

        if ($generation->image_path !== null) {
            return;
        }

        if ($generation->image_url === null) {
            return;
        }

        $response = Http::timeout(30)->get($generation->image_url);

        if ($response->failed()) {
            throw new RuntimeException("Could not download the image for generation [{$generation->uuid}].");
        }

        if (! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
            throw new RuntimeException("Generation [{$generation->uuid}] image URL did not return an image.");
        }

        $imagePath = "generations/{$generation->uuid}.png";

        Storage::disk('public')->put($imagePath, $response->body());

        $generation->update(['image_path' => $imagePath]);
    }
}
