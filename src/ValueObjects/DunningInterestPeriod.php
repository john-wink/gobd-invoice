<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\ValueObjects;

/**
 * One sub-period of a Verzugszins calculation. Because the Basiszinssatz changes
 * every half-year, statutory interest over a longer span is computed piecewise —
 * each sub-period with its own annual rate and its own year length (act/act,
 * 365 or 366) — and summed. This row records that breakdown for transparency and
 * for reproduction on the dunning notice.
 */
final readonly class DunningInterestPeriod
{
    /**
     * @param  string  $from  ISO date the sub-period starts (exclusive of interest for that day)
     * @param  string  $to  ISO date the sub-period ends (inclusive)
     * @param  int  $days  actual calendar days in the sub-period
     * @param  int  $yearDays  denominator for act/act (365, or 366 in a leap year)
     * @param  string  $annualRatePercent  Basiszinssatz + §288 surcharge, e.g. "10.27"
     */
    public function __construct(
        public string $from,
        public string $to,
        public int $days,
        public int $yearDays,
        public string $annualRatePercent,
        public Money $amount,
    ) {}
}
