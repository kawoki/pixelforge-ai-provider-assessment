<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
final class GenerationFactory extends Factory
{
    /**
     * Pixelforge's documented rates (USD per 1M tokens) plus our 25% markup.
     *
     * @return array{markup: string, rates: array<string, string>}
     */
    public static function testRateCard(): array
    {
        return [
            'markup' => '0.25',
            'rates' => ['input_text' => '5.00', 'input_image' => '10.00', 'output' => '40.00', 'reasoning' => '12.50'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'client_reference' => (string) Str::uuid(),
            'prompt' => fake()->sentence(),
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_image_urls' => [],
            'status' => GenerationStatus::Pending,
            'rate_card' => self::testRateCard(),
            'estimated_user_cost_min' => '0.058050000000',
            'estimated_user_cost_max' => '0.086300000000',
        ];
    }

    public function queued(): static
    {
        return $this->state(fn (): array => [
            'status' => GenerationStatus::Queued,
            'provider_generation_id' => 'gen_'.Str::lower(Str::random(16)),
            'submission_attempts' => 1,
            'submitted_at' => now(),
        ]);
    }

    public function processing(): static
    {
        return $this->queued()->state(fn (): array => [
            'status' => GenerationStatus::Processing,
            'started_at' => now(),
        ]);
    }

    public function succeeded(): static
    {
        return $this->processing()->state(fn (): array => [
            'status' => GenerationStatus::Succeeded,
            // 10×5 + 1100×40 + 1000×12.5 = 56,550 µ$ → $0.05655; ×1.25 = $0.0706875
            'usage' => ['input_text' => 10, 'input_image' => 0, 'output' => 1100, 'reasoning' => 1000],
            'actual_provider_cost' => '0.056550000000',
            'actual_user_cost' => '0.070687500000',
            'image_url' => 'https://pixelforge.promptedit.dev/images/'.Str::random(12).'.png',
            'image_expires_at' => now()->addDay(),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->queued()->state(fn (): array => [
            'status' => GenerationStatus::Failed,
            'failure_code' => 'generation_failed',
            'failure_message' => 'The generation failed at Pixelforge.',
            'completed_at' => now(),
        ]);
    }
}
