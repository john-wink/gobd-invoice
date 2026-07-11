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

it('accepts real dates whose day and month are extracted by position', function (string $from): void {
    // Includes a leap day and days ending in 0 / at the month boundary so that
    // an off-by-one in the positional month/day extraction turns a valid date
    // into an invalid one and is caught here.
    expect((new TaxRatePeriod($from, '19.0', '7.0'))->from)->toBe($from);
})->with(['2020-02-29', '2020-04-30', '2020-12-30', '2020-12-31', '2020-10-20']);

it('rejects a shape that passes checkdate but is not exactly YYYY-MM-DD', function (string $from): void {
    // Single-digit month/day still parse to a real date via checkdate, so only
    // the ISO-shape guard rejects them — this isolates that guard.
    expect(fn (): TaxRatePeriod => new TaxRatePeriod($from, '19.0', '7.0'))
        ->toThrow(InvalidArgumentException::class);
})->with(['2021-1-01', '2021-01-1', '2021-1-1', '2021-01-015']);

it('rejects malformed dates and non-canonical rates (fail loud)', function (callable $factory): void {
    expect($factory)->toThrow(InvalidArgumentException::class);
})->with([
    'wrong date format' => [fn (): TaxRatePeriod => new TaxRatePeriod('01.01.2021', '19.0', '7.0')],
    'impossible day' => [fn (): TaxRatePeriod => new TaxRatePeriod('2021-02-30', '19.0', '7.0')],
    'impossible month' => [fn (): TaxRatePeriod => new TaxRatePeriod('2021-13-01', '19.0', '7.0')],
    'zero month' => [fn (): TaxRatePeriod => new TaxRatePeriod('2021-00-10', '19.0', '7.0')],
    'zero day' => [fn (): TaxRatePeriod => new TaxRatePeriod('2021-01-00', '19.0', '7.0')],
    'non-leap 29 Feb' => [fn (): TaxRatePeriod => new TaxRatePeriod('2021-02-29', '19.0', '7.0')],
    'non-numeric standard' => [fn (): TaxRatePeriod => new TaxRatePeriod('2021-01-01', 'x', '7.0')],
    'empty reduced' => [fn (): TaxRatePeriod => new TaxRatePeriod('2021-01-01', '19.0', '')],
    'negative rate' => [fn (): TaxRatePeriod => new TaxRatePeriod('2021-01-01', '-19.0', '7.0')],
    'whitespace rate' => [fn (): TaxRatePeriod => new TaxRatePeriod('2021-01-01', ' 19.0', '7.0')],
    'scientific rate' => [fn (): TaxRatePeriod => new TaxRatePeriod('2021-01-01', '1e1', '7.0')],
]);
