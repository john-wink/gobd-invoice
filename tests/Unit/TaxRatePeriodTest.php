<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Enums\TaxRateKind;
use JohnWink\GobdInvoice\ValueObjects\TaxRatePeriod;

it('returns the rate for the requested kind', function (): void {
    $period = new TaxRatePeriod('2021-01-01', '19.0', '7.0');

    expect($period->rateFor(TaxRateKind::Standard))->toBe('19.0')
        ->and($period->rateFor(TaxRateKind::Reduced))->toBe('7.0');
});

it('applies on its start date and after, but not before', function (): void {
    $period = new TaxRatePeriod('2021-01-01', '19.0', '7.0');

    expect($period->appliesOn('2021-01-01'))->toBeTrue()
        ->and($period->appliesOn('2025-06-30'))->toBeTrue()
        ->and($period->appliesOn('2020-12-31'))->toBeFalse();
});

it('rejects malformed dates and non-canonical rates (fail loud)', function (callable $factory): void {
    expect($factory)->toThrow(InvalidArgumentException::class);
})->with([
    'wrong date format' => [fn (): TaxRatePeriod => new TaxRatePeriod('01.01.2021', '19.0', '7.0')],
    'impossible day' => [fn (): TaxRatePeriod => new TaxRatePeriod('2021-02-30', '19.0', '7.0')],
    'impossible month' => [fn (): TaxRatePeriod => new TaxRatePeriod('2021-13-01', '19.0', '7.0')],
    'non-numeric standard' => [fn (): TaxRatePeriod => new TaxRatePeriod('2021-01-01', 'x', '7.0')],
    'empty reduced' => [fn (): TaxRatePeriod => new TaxRatePeriod('2021-01-01', '19.0', '')],
    'negative rate' => [fn (): TaxRatePeriod => new TaxRatePeriod('2021-01-01', '-19.0', '7.0')],
    'whitespace rate' => [fn (): TaxRatePeriod => new TaxRatePeriod('2021-01-01', ' 19.0', '7.0')],
    'scientific rate' => [fn (): TaxRatePeriod => new TaxRatePeriod('2021-01-01', '1e1', '7.0')],
]);
