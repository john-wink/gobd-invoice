<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Contracts\TaxRateResolver;
use JohnWink\GobdInvoice\Enums\TaxRateKind;

it('resolves the container-bound resolver from configured rate periods', function (): void {
    config()->set('gobd-invoice.tax.rate_periods', [
        ['from' => '2020-07-01', 'standard' => '16.0', 'reduced' => '5.0'],
        ['from' => '2021-01-01', 'standard' => '19.0', 'reduced' => '7.0'],
    ]);

    $resolver = app(TaxRateResolver::class);

    expect($resolver->resolve(TaxRateKind::Standard, new DateTimeImmutable('2020-09-01'))->percent())->toBe('16.0')
        ->and($resolver->resolve(TaxRateKind::Standard, new DateTimeImmutable('2026-01-01'))->percent())->toBe('19.0');
});

it('fails loud on a structurally malformed period entry', function (): void {
    config()->set('gobd-invoice.tax.rate_periods', ['garbage']);

    expect(fn (): mixed => app(TaxRateResolver::class))->toThrow(InvalidArgumentException::class);
});

it('fails loud on a period with an invalid date rather than silently using a wrong rate', function (): void {
    config()->set('gobd-invoice.tax.rate_periods', [
        ['from' => 'not-a-date', 'standard' => '16.0', 'reduced' => '5.0'],
    ]);

    expect(fn (): mixed => app(TaxRateResolver::class))->toThrow(InvalidArgumentException::class);
});

it('rejects a boolean rate instead of silently coercing true to a 1% rate', function (): void {
    config()->set('gobd-invoice.tax.rate_periods', [
        ['from' => '2021-01-01', 'standard' => true, 'reduced' => '7.0'],
    ]);

    expect(fn (): mixed => app(TaxRateResolver::class))->toThrow(InvalidArgumentException::class);
});
