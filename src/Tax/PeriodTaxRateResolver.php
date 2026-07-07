<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Tax;

use DateTimeInterface;
use JohnWink\GobdInvoice\Contracts\TaxRateResolver;
use JohnWink\GobdInvoice\Enums\TaxCategory;
use JohnWink\GobdInvoice\Enums\TaxRateKind;
use JohnWink\GobdInvoice\ValueObjects\TaxRate;
use JohnWink\GobdInvoice\ValueObjects\TaxRatePeriod;

/**
 * The default {@see TaxRateResolver}. It picks the latest {@see TaxRatePeriod}
 * whose start date is on or before the supplied date; if none applies (or no
 * periods are configured) it falls back to the flat standard/reduced rates. Both
 * §12 rates map to EN 16931 category `S`.
 */
final readonly class PeriodTaxRateResolver implements TaxRateResolver
{
    /**
     * @param  array<int, TaxRatePeriod>  $periods
     */
    public function __construct(
        private array $periods = [],
        private string $standardFallback = '19.0',
        private string $reducedFallback = '7.0',
    ) {}

    public function resolve(TaxRateKind $taxRateKind, DateTimeInterface $on): TaxRate
    {
        $isoDate = $on->format('Y-m-d');

        $applicable = null;
        foreach ($this->periods as $period) {
            if ($period->appliesOn($isoDate) && ($applicable === null || $period->from > $applicable->from)) {
                $applicable = $period;
            }
        }

        $rate = $applicable instanceof TaxRatePeriod
            ? $applicable->rateFor($taxRateKind)
            : $this->fallbackFor($taxRateKind);

        return new TaxRate($rate, TaxCategory::Standard);
    }

    private function fallbackFor(TaxRateKind $taxRateKind): string
    {
        return match ($taxRateKind) {
            TaxRateKind::Standard => $this->standardFallback,
            TaxRateKind::Reduced => $this->reducedFallback,
        };
    }
}
