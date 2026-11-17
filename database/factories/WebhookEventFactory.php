<?php

namespace Database\Factories;

use App\Enums\WebhookEventStatus;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEvent>
 */
class WebhookEventFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $eventId = 'evt_'.Str::upper(Str::random(26));

        return [
            'provider' => 'pixelforge',
            'provider_event_id' => $eventId,
            'event_type' => 'ping',
            'provider_generation_id' => null,
            'payload' => ['id' => $eventId, 'object' => 'event', 'type' => 'ping', 'data' => []],
            'delivery_attempt' => 1,
            'processing_status' => WebhookEventStatus::Pending,
        ];
    }
}
