<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\ValueObjects;

use InvalidArgumentException;

/**
 * The German Basiszinssatz (§ 247 BGB) in force from {@see self::from} until the
 * next period. The Deutsche Bundesbank resets it every 1 January and 1 July, so
 * the periods are always half-year boundaries; §288 default interest is this
 * base rate plus a surcharge.
 *
 * The rate is an exact decimal string and MAY be negative (it was, from mid-2013
 * to mid-2022, as low as -0.88 %). A period is a legal fact — ship only values
 * verified against the Bundesbank publication.
 */
final readonly class BasiszinssatzPeriod
{
    /**
     * @param  string  $from  ISO-8601 calendar date (YYYY-MM-DD) the rate takes effect
     * @param  string  $rate  the base rate as a decimal percentage, e.g. "1.52" or "-0.88"
     */
    public function __construct(
        public string $from,
        public string $rate,
    ) {
        throw_if(preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1, InvalidArgumentException::class, "Period start must be an ISO date (YYYY-MM-DD), got [{$from}].");
        throw_unless(checkdate((int) mb_substr($from, 5, 2), (int) mb_substr($from, 8, 2), (int) mb_substr($from, 0, 4)), InvalidArgumentException::class, "Period start is not a real calendar date: [{$from}].");
        // Unlike a VAT rate, the Basiszinssatz may be negative; forbid only the
        // non-numeric shapes is_numeric() would wrongly admit (whitespace, "1e1").
        throw_if(preg_match('/^-?\d+(\.\d+)?$/', $rate) !== 1, InvalidArgumentException::class, "Base rate must be a decimal, got [{$rate}].");
    }

    /** Whether this period is already in force on the given ISO date. */
    public function appliesOn(string $isoDate): bool
    {
        return $isoDate >= $this->from;
    }
}
