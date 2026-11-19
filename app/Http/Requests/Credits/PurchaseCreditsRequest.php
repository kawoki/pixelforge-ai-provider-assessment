<?php

declare(strict_types=1);

namespace App\Http\Requests\Credits;

use App\Services\Credits\CreditPackCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Only a pack id from the server's list is accepted, never an amount (ADR-0013).
 * The idempotency key is issued by the server with the page, one per form render.
 */
final class PurchaseCreditsRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'pack' => ['required', 'string', Rule::in(app(CreditPackCatalog::class)->ids())],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
