<?php

declare(strict_types=1);

namespace App\Data;

/**
 * What a user asked Pixelforge to generate. Validated before construction (FormRequest).
 */
final readonly class GenerationRequestData
{
    /**
     * @param  list<string>  $referenceImageUrls
     */
    public function __construct(
        public string $prompt,
        public string $size,
        public string $quality,
        public array $referenceImageUrls = [],
    ) {}
}
