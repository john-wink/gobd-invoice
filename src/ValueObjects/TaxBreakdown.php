<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\ValueObjects;

/**
 * The full VAT breakdown of a document: one {@see TaxBreakdownLine} per
 * (category, rate) group plus the document totals. Amounts are already rounded
 * per group; the totals are sums of already-rounded values so they reconcile
 * exactly (EN 16931 zero rounding tolerance).
 */
final readonly class TaxBreakdown
{
    /**
     * @param  array<int, TaxBreakdownLine>  $lines
     */
    public function __construct(
        public array $lines,
        public Money $netTotal,
        public Money $vatTotal,
        public Money $grossTotal,
    ) {}

    /**
     * @return array<int, TaxBreakdownLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }
}
