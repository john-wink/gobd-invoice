<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\ValueObjects;

use InvalidArgumentException;

/**
 * A currency conversion rate: {@see self::rate} quote-currency units per one
 * base-currency unit. Used to express a non-EUR invoice's VAT total in the
 * accounting currency (EN 16931 BT-6 / BT-111) — for German VAT the accounting
 * currency is EUR and the legally-prescribed rate is the BMF monthly average
 * (Durchschnittskurs, §16 Abs. 6 UStG).
 *
 * The rate carries an optional {@see self::reference} (e.g. the BMF publication
 * month) so the conversion is reproducible for GoBD. The rate value itself is
 * host-supplied; this package does not fetch exchange rates.
 *
 * See docs/research/06-money-tax-and-rounding.md (Section 7, REQ-17/REQ-18).
 */
final readonly class ExchangeRate
{
    public function __construct(
        public string $baseCurrency,
        public string $quoteCurrency,
        public string $rate,
        public ?string $reference = null,
    ) {
        throw_if(preg_match('/^[A-Z]{3}$/', $baseCurrency) !== 1, InvalidArgumentException::class, "Invalid ISO-4217 base currency: [{$baseCurrency}].");
        throw_if(preg_match('/^[A-Z]{3}$/', $quoteCurrency) !== 1, InvalidArgumentException::class, "Invalid ISO-4217 quote currency: [{$quoteCurrency}].");
        throw_if(preg_match('/^\d+(\.\d+)?$/', $rate) !== 1, InvalidArgumentException::class, "Exchange rate must be a decimal number, got [{$rate}].");
        // A conversion rate must be strictly positive — a zero rate would book a
        // non-zero VAT total as 0.00 in the accounting currency (wrong fact).
        throw_if(preg_match('/^0+(\.0+)?$/', $rate) === 1, InvalidArgumentException::class, "Exchange rate must be positive, got [{$rate}].");
    }

    /**
     * Convert a {@see Money} amount in the base currency to the quote currency,
     * rounding once. The amount's currency must match {@see self::baseCurrency}.
     */
    public function convert(Money $money): Money
    {
        throw_if($money->currency !== $this->baseCurrency, InvalidArgumentException::class, "Cannot convert [{$money->currency}] with a [{$this->baseCurrency}]->[{$this->quoteCurrency}] rate.");

        return $money->convertedTo($this->quoteCurrency, $this->rate);
    }
}
