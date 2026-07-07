<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Enums\TaxRateKind;
use JohnWink\GobdInvoice\Tax\PeriodTaxRateResolver;
use JohnWink\GobdInvoice\ValueObjects\TaxRatePeriod;

function on(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date);
}

it('falls back to the flat rates when no periods are configured', function (): void {
    $resolver = new PeriodTaxRateResolver;

    $standard = $resolver->resolve(TaxRateKind::Standard, on('2026-07-07'));
    $reduced = $resolver->resolve(TaxRateKind::Reduced, on('2026-07-07'));

    expect($standard->percent())->toBe('19.0')
        ->and($standard->categoryCode())->toBe('S')  // both §12 rates are category S
        ->and($reduced->percent())->toBe('7.0')
        ->and($reduced->categoryCode())->toBe('S');
});

it('picks the rate in force on the service date across multiple periods', function (): void {
    // Illustrative periods (the 16/5 reduction is a historical fact; here it is
    // test data, not shipped config): 16/5 from 2020-07-01, reverting to 19/7.
    $resolver = new PeriodTaxRateResolver([
        new TaxRatePeriod('2020-07-01', '16.0', '5.0'),
        new TaxRatePeriod('2021-01-01', '19.0', '7.0'),
    ]);

    expect($resolver->resolve(TaxRateKind::Standard, on('2020-08-15'))->percent())->toBe('16.0')
        ->and($resolver->resolve(TaxRateKind::Reduced, on('2020-08-15'))->percent())->toBe('5.0')
        ->and($resolver->resolve(TaxRateKind::Standard, on('2021-06-01'))->percent())->toBe('19.0')
        // On the boundary date the new period already applies (inclusive).
        ->and($resolver->resolve(TaxRateKind::Standard, on('2021-01-01'))->percent())->toBe('19.0');
});

it('uses the flat fallback for a date before the earliest configured period', function (): void {
    $resolver = new PeriodTaxRateResolver(
        [new TaxRatePeriod('2021-01-01', '19.0', '7.0')],
        '19.0',
        '7.0',
    );

    // A 2019 service date precedes every period -> flat fallback.
    expect($resolver->resolve(TaxRateKind::Standard, on('2019-05-01'))->percent())->toBe('19.0');
});
