<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\ValueObjects;

use InvalidArgumentException;
use JohnWink\GobdInvoice\Contracts\TaxableLine;

/**
 * A price reduction (Rabatt / allowance) or surcharge (Zuschlag / charge),
 * usable at line level (EN 16931 BT-136/BG-27 allowance, BT-141/BG-28 charge) or
 * document level (BT-107/BG-20 allowance, BT-108/BG-21 charge).
 *
 * Every allowance/charge carries its own {@see TaxRate} so a document-level
 * adjustment folds into the correct (category, rate) VAT group (REQ-14). The
 * {@see self::amount} is always a positive magnitude expressed as a net value
 * (EN 16931 allowance/charge amounts are net); direction is carried by
 * {@see self::isCharge}. Because it exposes a signed net and a rate, it is itself
 * a {@see TaxableLine}: the totals calculator can group it alongside real lines.
 *
 * See docs/research/06-money-tax-and-rounding.md (Section 6).
 */
final readonly class AllowanceCharge implements TaxableLine
{
    private function __construct(
        public bool $isCharge,
        public Money $amount,
        public TaxRate $taxRate,
        public ?string $reason = null,
        public ?string $percentage = null,
        public ?Money $baseAmount = null,
    ) {
        throw_if($amount->isNegative(), InvalidArgumentException::class, 'An allowance/charge amount must be a positive magnitude; direction is carried by isCharge.');
    }

    /** A fixed-amount allowance (Rabatt / price reduction). */
    public static function allowance(Money $money, TaxRate $taxRate, ?string $reason = null): self
    {
        return new self(false, $money, $taxRate, $reason);
    }

    /** A fixed-amount charge (Zuschlag / surcharge). */
    public static function charge(Money $money, TaxRate $taxRate, ?string $reason = null): self
    {
        return new self(true, $money, $taxRate, $reason);
    }

    /**
     * A percentage allowance (BT-138 % of the BT-137 base). The amount is
     * resolved and rounded once to whole minor units; the percentage and base are
     * retained for the e-invoice mapping.
     */
    public static function percentageAllowance(string $percentage, Money $money, TaxRate $taxRate, ?string $reason = null): self
    {
        return new self(false, $money->percentage($percentage), $taxRate, $reason, $percentage, $money);
    }

    /** A percentage charge (BT-143 % of the BT-142 base). */
    public static function percentageCharge(string $percentage, Money $money, TaxRate $taxRate, ?string $reason = null): self
    {
        return new self(true, $money->percentage($percentage), $taxRate, $reason, $percentage, $money);
    }

    /**
     * The signed net contribution to the VAT base: a charge adds, an allowance
     * subtracts. This is the line's {@see TaxableLine} net amount, so grouping an
     * allowance/charge with the document lines apportions it into the right
     * (category, rate) group before VAT is computed (REQ-14).
     */
    public function netAmount(): Money
    {
        return $this->isCharge ? $this->amount : $this->amount->negated();
    }

    public function taxRate(): TaxRate
    {
        return $this->taxRate;
    }
}
