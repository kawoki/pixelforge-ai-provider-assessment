<?php

declare(strict_types=1);

use App\Exceptions\Money\MoneyPrecisionExceededException;
use App\Support\Money\Usd;

it('adds without floating point error', function () {
    expect(Usd::of('0.1')->plus(Usd::of('0.2'))->equals(Usd::of('0.3')))->toBeTrue();
});

it('always holds 12 decimal places', function () {
    expect(Usd::of('1.5')->toDecimalString())->toBe('1.500000000000')
        ->and(Usd::of(3)->toDecimalString())->toBe('3.000000000000');
});

it('accepts trailing zeros beyond the scale', function () {
    expect(Usd::of('0.1000000000000000')->toDecimalString())->toBe('0.100000000000');
});

it('refuses to silently lose precision', function () {
    Usd::of('0.0000000000001');
})->throws(MoneyPrecisionExceededException::class);

it('refuses a multiplication whose exact result does not fit', function () {
    Usd::of('0.000000000001')->multipliedBy('1.25');
})->throws(MoneyPrecisionExceededException::class);

it('multiplies exactly', function () {
    expect(Usd::of('0.04002')->multipliedBy('1.25')->toDecimalString())->toBe('0.050025000000');
});

it('subtracts, negates and compares', function () {
    $a = Usd::of('5');
    $b = Usd::of('7.25');

    expect($a->minus($b)->toDecimalString())->toBe('-2.250000000000')
        ->and($a->minus($b)->isNegative())->toBeTrue()
        ->and($b->negated()->equals(Usd::of('-7.25')))->toBeTrue()
        ->and($b->isGreaterThan($a))->toBeTrue()
        ->and($a->isLessThan($b))->toBeTrue()
        ->and(Usd::zero()->isZero())->toBeTrue();
});

it('returns the same instance when given a Usd', function () {
    $usd = Usd::of('1');

    expect(Usd::of($usd))->toBe($usd);
});

it('formats for display, rounding half up', function (string $amount, int $decimals, string $expected) {
    expect(Usd::of($amount)->format($decimals))->toBe($expected);
})->with([
    'sub-cent' => ['0.04255', 4, '$0.0426'],
    'grouping' => ['1234567.5', 2, '$1,234,567.50'],
    'negative' => ['-0.0425', 4, '-$0.0425'],
    'negative rounds to zero has no sign' => ['-0.00001', 4, '$0.0000'],
    'zero' => ['0', 2, '$0.00'],
    'carry into thousands' => ['999.995', 2, '$1,000.00'],
    'no decimals' => ['12.5', 0, '$13'],
]);

it('serializes to a full-precision string', function () {
    expect(json_encode(['cost' => Usd::of('0.0425')]))->toBe('{"cost":"0.042500000000"}')
        ->and((string) Usd::of('2'))->toBe('2.000000000000');
});
