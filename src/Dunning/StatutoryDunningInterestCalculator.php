<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Dunning;

use DateTimeImmutable;
use DateTimeInterface;
use JohnWink\GobdInvoice\Contracts\DunningInterestCalculator;
use JohnWink\GobdInvoice\Enums\DebtorType;
use JohnWink\GobdInvoice\Exceptions\DunningException;
use JohnWink\GobdInvoice\ValueObjects\BasiszinssatzPeriod;
use JohnWink\GobdInvoice\ValueObjects\DunningAssessment;
use JohnWink\GobdInvoice\ValueObjects\DunningInterestPeriod;
use JohnWink\GobdInvoice\ValueObjects\DunningOptions;
use JohnWink\GobdInvoice\ValueObjects\Money;

/**
 * Computes §288 BGB default interest with BCMath (never a float).
 *
 * The rate is the Basiszinssatz in force plus the §288 surcharge (consumer +5,
 * business +9 percentage points). Because the Basiszinssatz resets every 1 Jan
 * and 1 Jul, interest over a longer span is computed piecewise — the interval is
 * split at each half-year boundary and each segment is charged with its own base
 * rate and its own year length (act/act: 365, or 366 in a leap year). Interest is
 * simple (§289 BGB forbids compounding), runs from the day AFTER Verzugsbeginn up
 * to and including the valuation day (§187 Abs. 1), and is charged on the gross
 * (Brutto) principal. The €40 flat fee (§288 Abs. 5) applies only to a business
 * debtor. See docs/research and the Bundesbank Basiszinssatz publication.
 *
 * @internal precision constant for intermediate BCMath results.
 */
final readonly class StatutoryDunningInterestCalculator implements DunningInterestCalculator
{
    private const int BC_SCALE = 10;

    /**
     * @param  array<int, BasiszinssatzPeriod>  $baseRatePeriods
     * @param  string  $consumerSurchargePoints  §288 Abs. 1 add-on (e.g. "5.0")
     * @param  string  $businessSurchargePoints  §288 Abs. 2 add-on (e.g. "9.0")
     * @param  int  $latePaymentFeeMinor  §288 Abs. 5 flat fee in minor units (e.g. 4000)
     */
    public function __construct(
        private array $baseRatePeriods,
        private string $consumerSurchargePoints,
        private string $businessSurchargePoints,
        private int $latePaymentFeeMinor,
    ) {}

    public function assess(Money $money, DunningOptions $dunningOptions): DunningAssessment
    {
        [$interest, $periods] = $this->interest($money, $dunningOptions);

        // §288 Abs. 5 presupposes an overdue Entgeltforderung in EUR: no fee on a
        // settled (zero) principal, and the fixed €40 is a EUR amount, so it is
        // only applied to a EUR debt (a foreign-currency debt would mislabel it).
        $applyFee = $dunningOptions->debtorType->allowsLatePaymentFee()
            && ($dunningOptions->withLatePaymentFee ?? $dunningOptions->withInterest)
            && $money->minorUnits > 0
            && $money->currency === 'EUR';

        $latePaymentFee = Money::fromMinorUnits($applyFee ? $this->latePaymentFeeMinor : 0, $money->currency);
        $dunningFee = Money::fromMinorUnits($dunningOptions->dunningFeeMinor, $money->currency);

        return new DunningAssessment(
            $money,
            $interest,
            $latePaymentFee,
            $dunningFee,
            $dunningOptions->debtorType,
            $dunningOptions->level,
            $periods,
        );
    }

    /**
     * @return array{Money, list<DunningInterestPeriod>}
     */
    private function interest(Money $money, DunningOptions $dunningOptions): array
    {
        if (! $dunningOptions->withInterest) {
            return [Money::zero($money->currency), []];
        }

        if (! $dunningOptions->interestFrom instanceof DateTimeInterface || ! $dunningOptions->interestTo instanceof DateTimeInterface) {
            throw DunningException::missingInterestPeriod();
        }

        $surcharge = $dunningOptions->debtorType === DebtorType::Business
            ? $this->businessSurchargePoints
            : $this->consumerSurchargePoints;

        // Interest accrues over (Verzugsbeginn, valuation] — from the day AFTER
        // Verzugsbeginn (§187 Abs. 1) up to and including the valuation day. The
        // cursor walks that accrual window, so the rate looked up at a segment's
        // start is the rate on a day interest is actually charged for; this puts
        // the half-year changeover day (1 Jan / 1 Jul) in the NEW-rate segment.
        $cursor = new DateTimeImmutable($dunningOptions->interestFrom->format('Y-m-d'))->modify('+1 day');
        $end = new DateTimeImmutable($dunningOptions->interestTo->format('Y-m-d'))->modify('+1 day');

        $periods = [];
        $totalMinor = 0;

        while ($cursor < $end) {
            $segmentEnd = min($this->nextHalfYearBoundary($cursor), $end);
            $days = (int) $cursor->diff($segmentEnd)->days;

            if ($days === 0) {
                $cursor = $segmentEnd;

                continue;
            }

            $annualRate = $this->annualRate($cursor, $surcharge);
            $yearDays = $this->daysInYear($cursor);

            $amountMinor = $this->segmentInterest($money->minorUnits, $annualRate, $days, $yearDays);
            $totalMinor += $amountMinor;

            $periods[] = new DunningInterestPeriod(
                $cursor->format('Y-m-d'),
                $segmentEnd->modify('-1 day')->format('Y-m-d'), // last accrual day (inclusive)
                $days,
                $yearDays,
                $annualRate,
                Money::fromMinorUnits($amountMinor, $money->currency),
            );

            $cursor = $segmentEnd;
        }

        return [Money::fromMinorUnits($totalMinor, $money->currency), $periods];
    }

    /**
     * The annual percentage = Basiszinssatz on the date + §288 surcharge, floored
     * at 0 (a creditor never owes the debtor default interest).
     *
     * @return numeric-string
     */
    private function annualRate(DateTimeInterface $on, string $surcharge): string
    {
        $base = $this->baseRateOn($on);

        // Both are validated decimals (the period VO / config); re-narrow to a
        // numeric-string here so BCMath is type-safe, failing loud on bad config.
        if (! is_numeric($base)) {
            throw DunningException::nonNumericRate($base);
        }
        if (! is_numeric($surcharge)) {
            throw DunningException::nonNumericRate($surcharge);
        }

        $rate = bcadd($base, $surcharge, self::BC_SCALE);

        if (bccomp($rate, '0', self::BC_SCALE) < 0) {
            return '0';
        }

        // Trim the BCMath scale padding to a canonical decimal (10.2700 -> 10.27).
        $canonical = str_contains($rate, '.') ? mb_rtrim(mb_rtrim($rate, '0'), '.') : $rate;

        return is_numeric($canonical) ? $canonical : $rate;
    }

    private function baseRateOn(DateTimeInterface $on): string
    {
        $isoDate = $on->format('Y-m-d');

        $applicable = null;
        foreach ($this->baseRatePeriods as $baseRatePeriod) {
            if ($baseRatePeriod->appliesOn($isoDate) && ($applicable === null || $baseRatePeriod->from > $applicable->from)) {
                $applicable = $baseRatePeriod;
            }
        }

        if (! $applicable instanceof BasiszinssatzPeriod) {
            throw DunningException::noBaseRateForDate($isoDate);
        }

        return $applicable->rate;
    }

    /**
     * principal × rate% × days / yearDays, rounded to whole minor units
     * (kaufmännische Rundung, half away from zero).
     *
     * @param  numeric-string  $annualRate
     */
    private function segmentInterest(int $principalMinor, string $annualRate, int $days, int $yearDays): int
    {
        $numerator = bcmul(bcmul((string) $principalMinor, $annualRate, self::BC_SCALE), (string) $days, self::BC_SCALE);
        $denominator = bcmul('100', (string) $yearDays, self::BC_SCALE);
        $exact = bcdiv($numerator, $denominator, self::BC_SCALE);

        return bccomp($exact, '0', self::BC_SCALE) >= 0
            ? (int) bcadd($exact, '0.5', 0)
            : (int) bcsub($exact, '0.5', 0);
    }

    /** The next 1 January or 1 July strictly after the cursor. */
    private function nextHalfYearBoundary(DateTimeImmutable $cursor): DateTimeImmutable
    {
        $year = (int) $cursor->format('Y');

        return (int) $cursor->format('n') < 7
            ? new DateTimeImmutable(sprintf('%04d-07-01', $year))
            : new DateTimeImmutable(sprintf('%04d-01-01', $year + 1));
    }

    private function daysInYear(DateTimeInterface $on): int
    {
        return ((int) $on->format('L')) === 1 ? 366 : 365;
    }
}
