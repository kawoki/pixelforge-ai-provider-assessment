<?php

declare(strict_types=1);

namespace App\Http\Requests\Generations;

final class StoreGenerationRequest extends GenerationInputRequest
{
    /**
     * @return list<string>
     */
    protected function promptRules(int $maxLength): array
    {
        return ['required', 'string', 'max:'.$maxLength];
    }
}
