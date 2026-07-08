<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\ValueObjects;

/**
 * A previously-issued Abschlags-/Anzahlungsrechnung (advance/progress invoice)
 * that a Schlussrechnung (final invoice) deducts. §14 Abs. 5 Satz 2 UStG
 * requires the final invoice to subtract both the advance's net (Teilentgelt)
 * AND the VAT shown on it — omitting the VAT deduction makes the supplier owe it
 * twice (§14c Abs. 1). The amounts are the ones AS SHOWN on the advance, so the
 * exact VAT is deducted (not a re-derivation). See
 * docs/research/05-document-types-and-lifecycle.md (§1.8).
 */
final readonly class AdvanceDeduction
{
    public function __construct(
        public Money $net,
        public Money $vat,
        public ?string $reference = null,
        public ?string $date = null,
    ) {}

    /** The advance's gross (net + VAT), the amount removed from the amount due. */
    public function gross(): Money
    {
        return $this->net->plus($this->vat);
    }
}
