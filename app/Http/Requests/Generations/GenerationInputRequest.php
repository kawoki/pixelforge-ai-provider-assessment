<?php

declare(strict_types=1);

namespace App\Http\Requests\Generations;

use App\Data\GenerationRequestData;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * Validation shared by estimating and submitting a generation. Mirrors Pixelforge's own
 * rules (PIXELFORGE_API.md → POST /v1/generations), so bad input never reaches Pixelforge.
 */
abstract class GenerationInputRequest extends FormRequest
{
    /**
     * @return array<string, list<ValidationRule|string|In>>
     */
    public function rules(): array
    {
        $options = config('pixelforge.options');

        return [
            'prompt' => $this->promptRules($options['prompt_max_length']),
            'size' => ['required', 'string', Rule::in($options['sizes'])],
            'quality' => ['required', 'string', Rule::in($options['qualities'])],
            'reference_image_urls' => ['sometimes', 'array', 'max:'.$options['max_reference_images']],
            'reference_image_urls.*' => ['required', 'string', 'max:2048', 'url:http,https', 'distinct'],
        ];
    }

    public function toData(): GenerationRequestData
    {
        return new GenerationRequestData(
            prompt: (string) $this->validated('prompt'),
            size: $this->validated('size'),
            quality: $this->validated('quality'),
            referenceImageUrls: array_values($this->validated('reference_image_urls', [])),
        );
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reference_image_urls.max' => 'You can add at most :max reference images.',
            'reference_image_urls.*.url' => 'Each reference image must be an http(s) URL.',
            'reference_image_urls.*.distinct' => 'This reference image is listed twice.',
        ];
    }

    /**
     * @return list<string>
     */
    abstract protected function promptRules(int $maxLength): array;
}
