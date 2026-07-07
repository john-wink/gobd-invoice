<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\ValueObjects;

use InvalidArgumentException;
use Stringable;

/**
 * An immutable monetary amount stored as integer minor units (e.g. cents) plus
 * an ISO-4217 currency. Money is NEVER represented as a float — see
 * docs/research/06-money-tax-and-rounding.md.
 *
 * VAT and percentage arithmetic use BCMath and apply kaufmännische Rundung
 * (commercial rounding, half away from zero) to whole minor units.
 */
final readonly class Money implements Stringable
{
    /** Minor-unit exponent (cents). EUR and all current targets use 2. */
    private const int SCALE = 2;

    /** Working precision for BCMath intermediate results. */
    private const int BC_SCALE = 8;

    /** 10 ** SCALE as a numeric-string literal (kept in sync with SCALE). */
    private const string MINOR_FACTOR = '100';

    public function __construct(
        public int $minorUnits,
        public string $currency = 'EUR',
    ) {
        throw_if(strlen($currency) !== 3 || strtoupper($currency) !== $currency, InvalidArgumentException::class, "Invalid ISO-4217 currency code: [{$currency}].");
    }

    public static function fromMinorUnits(int $minorUnits, string $currency = 'EUR'): self
    {
        return new self($minorUnits, $currency);
    }

    /**
     * Build from a decimal string such as "1234.56". At most two decimal places
     * are accepted; passing a float is rejected to avoid binary-float drift.
     */
    public static function fromDecimal(string $amount, string $currency = 'EUR'): self
    {
        throw_if(preg_match('/^-?\d+(\.\d{1,2})?$/', $amount) !== 1, InvalidArgumentException::class, "Invalid decimal money string: [{$amount}].");

        assert(is_numeric($amount));

        return new self((int) bcmul($amount, self::MINOR_FACTOR, 0), $currency);
    }

    public static function zero(string $currency = 'EUR'): self
    {
        return new self(0, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    /**
     * Multiply by a quantity (e.g. "2", "2.5" hours), rounding to whole minor
     * units. Accepts an integer or a decimal string for the same reason as
     * {@see self::fromDecimal()}.
     */
    public function multipliedBy(int|string $factor): self
    {
        $factorString = (string) $factor;
        throw_if(! is_numeric($factorString), InvalidArgumentException::class, "Invalid multiplier: [{$factorString}].");

        $product = bcmul((string) $this->minorUnits, $factorString, self::BC_SCALE);

        return new self($this->roundToInt($product), $this->currency);
    }

    /**
     * The VAT amount for the given rate (e.g. "19.0"), rounded once to whole
     * minor units (kaufmännische Rundung). EN 16931 has zero rounding
     * tolerance, so callers must round PER (category, rate) group, not per line.
     */
    public function percentage(string $rate): self
    {
        throw_if(! is_numeric($rate), InvalidArgumentException::class, "Invalid percentage rate: [{$rate}].");

        $product = bcmul((string) $this->minorUnits, $rate, self::BC_SCALE);
        $divided = bcdiv($product, '100', self::BC_SCALE);

        return new self($this->roundToInt($divided), $this->currency);
    }

    /**
     * Treat this as a gross (VAT-inclusive) amount and extract the net base for
     * `quantity` units at the given rate (e.g. "19.0"):
     * `net = (gross × quantity) × 100 / (100 + rate)`.
     *
     * The gross line extension (`gross × quantity`) is kept exact in BCMath and
     * the net is rounded to whole minor units exactly ONCE — there is no
     * intermediate cent rounding, so a fractional quantity does not introduce a
     * second, unsanctioned rounding point (REQ-9/REQ-10). A rate of "0" (untaxed
     * categories) returns the gross extension unchanged. Used for gross-authored
     * lines (Bruttorechnung); EN 16931 still transports the net base (BT-131), so
     * callers convert to net before grouping. See
     * docs/research/06-money-tax-and-rounding.md (Section 2/4, REQ-4/REQ-5).
     */
    public function netFromGross(string $rate, string $quantity = '1'): self
    {
        throw_if(! is_numeric($rate), InvalidArgumentException::class, "Invalid VAT rate: [{$rate}].");
        throw_if(! is_numeric($quantity), InvalidArgumentException::class, "Invalid quantity: [{$quantity}].");

        $grossExtension = bcmul((string) $this->minorUnits, $quantity, self::BC_SCALE);
        $denominator = bcadd('100', $rate, self::BC_SCALE);
        $net = bcdiv(bcmul($grossExtension, '100', self::BC_SCALE), $denominator, self::BC_SCALE);

        return new self($this->roundToInt($net), $this->currency);
    }

    public function negated(): self
    {
        return new self(-$this->minorUnits, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits && $this->currency === $other->currency;
    }

    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits <=> $other->minorUnits;
    }

    /**
     * The amount as a fixed two-decimal string, e.g. "1234.56" or "-12.00".
     */
    public function toDecimal(): string
    {
        $sign = $this->minorUnits < 0 ? '-' : '';
        $absolute = abs($this->minorUnits);
        $divisor = 10 ** self::SCALE;

        return sprintf('%s%d.%0'.self::SCALE.'d', $sign, intdiv($absolute, $divisor), $absolute % $divisor);
    }

    public function __toString(): string
    {
        return $this->toDecimal().' '.$this->currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Currency mismatch: [{$this->currency}] vs [{$other->currency}]."
            );
        }
    }

    /**
     * Round a BCMath decimal string to the nearest integer, half away from zero.
     *
     * @param  numeric-string  $value
     */
    private function roundToInt(string $value): int
    {
        return bccomp($value, '0', self::BC_SCALE) >= 0
            ? (int) bcadd($value, '0.5', 0)
            : (int) bcsub($value, '0.5', 0);
    }
}
