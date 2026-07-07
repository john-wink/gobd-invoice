<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Contracts\KleinunternehmerRule;
use JohnWink\GobdInvoice\ValueObjects\Money;

it('binds the rule with the configured 2025-reform limits', function (): void {
    $rule = app(KleinunternehmerRule::class);

    // Defaults: prior €25,000 / current €100,000.
    expect($rule->assess(Money::fromDecimal('24999.99'), Money::fromDecimal('99999.99'))->exempt)->toBeTrue()
        ->and($rule->assess(Money::fromDecimal('25000.01'), Money::fromDecimal('50000.00'))->exempt)->toBeFalse()
        ->and($rule->assess(Money::fromDecimal('10000.00'), Money::fromDecimal('100000.01'))->currentYearLimitExceeded)->toBeTrue();
});

it('honours overridden limits from config', function (): void {
    config()->set('gobd-invoice.tax.kleinunternehmer_limits.prior_year', '22000.00');

    $rule = app(KleinunternehmerRule::class);

    expect($rule->assess(Money::fromDecimal('23000.00'), Money::fromDecimal('50000.00'))->priorYearLimitExceeded)->toBeTrue();
});
