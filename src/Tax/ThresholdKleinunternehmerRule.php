<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Tax;

use JohnWink\GobdInvoice\Contracts\KleinunternehmerRule;
use JohnWink\GobdInvoice\ValueObjects\KleinunternehmerAssessment;
use JohnWink\GobdInvoice\ValueObjects\Money;

/**
 * The default {@see KleinunternehmerRule}: §19 UStG applies while the prior-year
 * turnover has NOT exceeded the lower limit AND the current-year turnover has
 * NOT exceeded the upper limit. "Exceeded" is strictly greater — a turnover
 * exactly at a limit is still within it ("nicht überschritten"). Exceeding the
 * upper limit is the mid-year Fallbeil that ends the exemption at once.
 *
 * In the year of commencement (Neugründung) there is no prior year, so the
 * current-year turnover alone decides against the LOWER limit (€25,000, no
 * projection since 2025); exceeding it in year one already ends the exemption
 * (BMF 2025-03-18; §19 Abs. 1 UStG).
 *
 * Turnover is supplied by the host (the package does not itself accumulate it);
 * the limits default to the 2025-reform values (€25,000 / €100,000) via config.
 */
final readonly class ThresholdKleinunternehmerRule implements KleinunternehmerRule
{
    public function __construct(
        private Money $priorYearLimit,
        private Money $currentYearLimit,
    ) {}

    public function assess(?Money $priorYearTurnover, Money $currentYearTurnover): KleinunternehmerAssessment
    {
        // Founding year (no prior year): only the current-year turnover matters,
        // measured against the LOWER limit; there is no prior-year test.
        if (! $priorYearTurnover instanceof Money) {
            $currentYearLimitExceeded = $currentYearTurnover->compareTo($this->priorYearLimit) > 0;

            return new KleinunternehmerAssessment(
                exempt: ! $currentYearLimitExceeded,
                priorYearLimitExceeded: false,
                currentYearLimitExceeded: $currentYearLimitExceeded,
            );
        }

        $priorYearLimitExceeded = $priorYearTurnover->compareTo($this->priorYearLimit) > 0;
        $currentYearLimitExceeded = $currentYearTurnover->compareTo($this->currentYearLimit) > 0;

        return new KleinunternehmerAssessment(
            exempt: ! $priorYearLimitExceeded && ! $currentYearLimitExceeded,
            priorYearLimitExceeded: $priorYearLimitExceeded,
            currentYearLimitExceeded: $currentYearLimitExceeded,
        );
    }
}
