<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\ValueObjects;

use InvalidArgumentException;
use JohnWink\GobdInvoice\Enums\TaxRateKind;

/**
 * A period during which a given pair of §12 UStG rates is in force. The rates
 * apply from {@see self::from} (inclusive, compared by calendar date on the
 * Leistungszeitpunkt) until the start of the next period.
 *
 * Rates are exact decimal strings. A period is a legal fact and must cite a
 * primary source where it deviates from the current rates (e.g. the temporary
 * 16 % / 5 % reduction 2020-07-01…2020-12-31, Zweites Corona-Steuerhilfegesetz).
 */
final readonly class TaxRatePeriod
{
    /**
     * @param  string  $from  ISO-8601 calendar date (YYYY-MM-DD) the rates take effect
     */
    public function __construct(
        public string $from,
        public string $standard,
        public string $reduced,
    ) {
        throw_if(preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1, InvalidArgumentException::class, "Period start must be an ISO date (YYYY-MM-DD), got [{$from}].");
        // Shape alone is not enough: reject impossible dates (e.g. 2021-02-30),
        // which would otherwise mis-order periods under the string comparison and
        // silently apply the wrong rate.
        throw_unless(checkdate((int) mb_substr($from, 5, 2), (int) mb_substr($from, 8, 2), (int) mb_substr($from, 0, 4)), InvalidArgumentException::class, "Period start is not a real calendar date: [{$from}].");
        // A VAT rate is a non-negative canonical decimal — is_numeric() would also
        // admit negatives ("-19"), whitespace and scientific notation ("1e1").
        throw_if(preg_match('/^\d+(\.\d+)?$/', $standard) !== 1, InvalidArgumentException::class, "Standard rate must be a non-negative decimal, got [{$standard}].");
        throw_if(preg_match('/^\d+(\.\d+)?$/', $reduced) !== 1, InvalidArgumentException::class, "Reduced rate must be a non-negative decimal, got [{$reduced}].");
    }

    /** The exact rate string for the requested kind. */
    public function rateFor(TaxRateKind $taxRateKind): string
    {
        return match ($taxRateKind) {
            TaxRateKind::Standard => $this->standard,
            TaxRateKind::Reduced => $this->reduced,
        };
    }

    /** Whether this period is already in force on the given ISO date. */
    public function appliesOn(string $isoDate): bool
    {
        return $isoDate >= $this->from;
    }
}
