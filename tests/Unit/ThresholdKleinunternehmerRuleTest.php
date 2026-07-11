<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Tax\ThresholdKleinunternehmerRule;
use JohnWink\GobdInvoice\ValueObjects\Money;

covers(ThresholdKleinunternehmerRule::class);

function ruleWithReformLimits(): ThresholdKleinunternehmerRule
{
    return new ThresholdKleinunternehmerRule(
        Money::fromDecimal('25000.00'),
        Money::fromDecimal('100000.00'),
    );
}

it('is exempt while both turnovers stay within the limits', function (): void {
    $assessment = ruleWithReformLimits()->assess(Money::fromDecimal('20000.00'), Money::fromDecimal('50000.00'));

    expect($assessment->exempt)->toBeTrue()
        ->and($assessment->priorYearLimitExceeded)->toBeFalse()
        ->and($assessment->currentYearLimitExceeded)->toBeFalse()
        ->and($assessment->taxCategory()->value)->toBe('E')
        ->and($assessment->noteTranslationKey())->toBe('gobd-invoice::gobd-invoice.notes.kleinunternehmer');
});

it('is not exempt when the prior year exceeded the lower limit', function (): void {
    $assessment = ruleWithReformLimits()->assess(Money::fromDecimal('30000.00'), Money::fromDecimal('50000.00'));

    expect($assessment->exempt)->toBeFalse()
        ->and($assessment->priorYearLimitExceeded)->toBeTrue()
        ->and($assessment->taxCategory()->value)->toBe('S')
        ->and($assessment->noteTranslationKey())->toBeNull();
});

it('ends the exemption at once when the current year exceeds the upper limit (Fallbeil)', function (): void {
    $assessment = ruleWithReformLimits()->assess(Money::fromDecimal('10000.00'), Money::fromDecimal('100000.01'));

    expect($assessment->exempt)->toBeFalse()
        ->and($assessment->currentYearLimitExceeded)->toBeTrue()
        ->and($assessment->taxCategory()->value)->toBe('S');
});

it('applies only the lower limit in the founding year (no prior year)', function (): void {
    $rule = ruleWithReformLimits();

    // €25,000 in the founding year is still within the limit.
    expect($rule->assess(null, Money::fromDecimal('25000.00'))->exempt)->toBeTrue()
        // €30,000 in year one is NOT exempt — it would be if the €100k limit wrongly applied.
        ->and($rule->assess(null, Money::fromDecimal('30000.00'))->exempt)->toBeFalse();

    // One cent over the founding-year limit ends the exemption (Fallbeil at €25k),
    // with no prior-year limit in play.
    $justOver = $rule->assess(null, Money::fromDecimal('25000.01'));

    expect($justOver->exempt)->toBeFalse()
        ->and($justOver->currentYearLimitExceeded)->toBeTrue()
        ->and($justOver->priorYearLimitExceeded)->toBeFalse();
});

it('treats a turnover exactly at a limit as within it (nicht überschritten)', function (): void {
    $atLimits = ruleWithReformLimits()->assess(Money::fromDecimal('25000.00'), Money::fromDecimal('100000.00'));

    expect($atLimits->exempt)->toBeTrue();

    // One cent over the lower limit already ends eligibility.
    $overPrior = ruleWithReformLimits()->assess(Money::fromDecimal('25000.01'), Money::fromDecimal('50000.00'));

    expect($overPrior->exempt)->toBeFalse()
        ->and($overPrior->priorYearLimitExceeded)->toBeTrue();
});
