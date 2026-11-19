<?php

declare(strict_types=1);

namespace App\Http\Requests\Generations;

/**
 * Same rules as submitting, except the prompt may still be empty while the user types.
 */
final class EstimateGenerationRequest extends GenerationInputRequest
{
    /**
     * @return list<string>
     */
    protected function promptRules(int $maxLength): array
    {
        return ['nullable', 'string', 'max:'.$maxLength];
    }
}
