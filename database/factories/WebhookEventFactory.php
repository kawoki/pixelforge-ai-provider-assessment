<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEvent>
 */
final class WebhookEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $generationId = 'gen_'.Str::lower(Str::random(16));

        return [
            'provider_event_id' => 'evt_'.Str::lower(Str::random(16)),
            'type' => 'generation.succeeded',
            'provider_generation_id' => $generationId,
            'payload' => ['id' => 'evt_example', 'data' => ['id' => $generationId]],
        ];
    }

    public function processed(): static
    {
        return $this->state(fn (): array => ['processed_at' => now()]);
    }
}
