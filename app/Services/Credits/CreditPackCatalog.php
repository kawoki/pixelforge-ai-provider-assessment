<?php

declare(strict_types=1);

namespace App\Services\Credits;

use App\Data\CreditPackData;
use App\Support\Money\Usd;
use Illuminate\Container\Attributes\Config;

/**
 * The credit packs on sale (config/credits.php).
 */
final readonly class CreditPackCatalog
{
    /**
     * @param  list<array{id: string, label: string, amount: string}>  $packs
     */
    public function __construct(
        #[Config('credits.packs')] private array $packs,
    ) {}

    /**
     * @return list<CreditPackData>
     */
    public function all(): array
    {
        return array_map(fn (array $pack): CreditPackData => new CreditPackData($pack['id'], $pack['label'], Usd::of($pack['amount'])), $this->packs);
    }

    public function find(string $id): ?CreditPackData
    {
        foreach ($this->all() as $pack) {
            if ($pack->id === $id) {
                return $pack;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_column($this->packs, 'id');
    }
}
