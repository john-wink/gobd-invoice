<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Exceptions;

/**
 * Raised when a dunning assessment cannot be computed correctly — interest was
 * requested without a period, or a date has no verified Basiszinssatz. It fails
 * loud rather than inventing a rate.
 */
final class DunningException extends GobdInvoiceException
{
    public static function missingInterestPeriod(): self
    {
        return new self('Verzugszins requires both interestFrom and interestTo; pass them or set withInterest: false.');
    }

    public static function noBaseRateForDate(string $isoDate): self
    {
        return new self(
            "No Basiszinssatz (§247 BGB) configured for [{$isoDate}]. Add the period to "
            .'`gobd-invoice.dunning.base_rate_periods` (verified against the Bundesbank publication).'
        );
    }

    public static function nonNumericRate(string $value): self
    {
        return new self("Dunning rate must be a decimal, got [{$value}]. Check `gobd-invoice.dunning.*`.");
    }
}
