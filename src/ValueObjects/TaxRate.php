<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\ValueObjects;

use InvalidArgumentException;
use JohnWink\GobdInvoice\Enums\TaxCategory;
use Stringable;

/**
 * A VAT rate: an exact decimal percentage (EN 16931 BT-119) paired with its
 * UNCL5305 category code (BT-118). Note that the German 19% and 7% rates BOTH
 * use category 'S' — they are distinguished by the percentage here, not by the
 * category. See docs/research/06-money-tax-and-rounding.md.
 */
final readonly class TaxRate implements Stringable
{
    public function __construct(
        public string $rate,
        public TaxCategory $category,
    ) {
        throw_if(! is_numeric($rate), InvalidArgumentException::class, "Invalid VAT rate: [{$rate}].");
    }

    public static function standard(string $rate = '19.0'): self
    {
        return new self($rate, TaxCategory::Standard);
    }

    public static function reduced(string $rate = '7.0'): self
    {
        return new self($rate, TaxCategory::Standard);
    }

    public static function zero(): self
    {
        return new self('0.0', TaxCategory::ZeroRated);
    }

    public static function exempt(): self
    {
        return new self('0.0', TaxCategory::Exempt);
    }

    public static function reverseCharge(): self
    {
        return new self('0.0', TaxCategory::ReverseCharge);
    }

    /** The EN 16931 BT-118 VAT category code. */
    public function categoryCode(): string
    {
        return $this->category->value;
    }

    /** The EN 16931 BT-119 VAT category rate (percentage). */
    public function percent(): string
    {
        return $this->rate;
    }

    /** The VAT amount for a net base, rounded to whole minor units. */
    public function vatOf(Money $money): Money
    {
        if (! $this->category->isTaxed()) {
            return Money::zero($money->currency);
        }

        return $money->percentage($this->rate);
    }

    /**
     * A stable grouping key for the VAT breakdown (Steuerausweis je Steuersatz):
     * VAT is summed per (category, rate) group. The rate is reduced to a
     * numerically canonical form so equivalent representations ("19", "19.0",
     * "19.00") share ONE breakdown group — EN 16931 BR-S-8 requires exactly one
     * row per (category, rate), and merging avoids a rounding drift that its
     * zero-tolerance validation would reject.
     */
    public function groupKey(): string
    {
        $rate = $this->rate;

        // The constructor guarantees a numeric rate; the is_numeric() check also
        // re-narrows it to numeric-string for the type checker before bcadd().
        $canonicalRate = is_numeric($rate)
            ? rtrim(rtrim(bcadd($rate, '0', 4), '0'), '.')
            : $rate;

        return $this->category->value.':'.$canonicalRate;
    }

    public function __toString(): string
    {
        return $this->rate.'% ('.$this->category->value.')';
    }
}
