<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\ValueObjects;

/**
 * One row of the VAT breakdown (Steuerausweis je Steuersatz): the net base, the
 * VAT computed and rounded once for the whole (category, rate) group, and the
 * resulting gross. See docs/research/06-money-tax-and-rounding.md.
 */
final readonly class TaxBreakdownLine
{
    public function __construct(
        public TaxRate $rate,
        public Money $net,
        public Money $vat,
        public Money $gross,
    ) {}
}
