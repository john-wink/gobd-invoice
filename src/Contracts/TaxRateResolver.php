<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Contracts;

use DateTimeInterface;
use JohnWink\GobdInvoice\Enums\TaxRateKind;
use JohnWink\GobdInvoice\ValueObjects\TaxRate;

/**
 * Resolves the §12 UStG standard or reduced VAT rate in force on a given date
 * (the Leistungszeitpunkt), so historical and transitional rates (e.g. the
 * temporary 16 %/5 % reduction, or a future Gastronomie change) are applied by
 * date rather than hard-coded. See docs/research/06-money-tax-and-rounding.md.
 */
interface TaxRateResolver
{
    /**
     * The Leistungszeitpunkt is a calendar date: `$on` is read in its OWN
     * timezone via its date part. Pass a date-only value (midnight) or a
     * Germany-local instant so a rate change taking effect at a day boundary
     * resolves on the correct calendar day — do not pass a UTC instant for a
     * near-midnight local time.
     */
    public function resolve(TaxRateKind $taxRateKind, DateTimeInterface $on): TaxRate;
}
