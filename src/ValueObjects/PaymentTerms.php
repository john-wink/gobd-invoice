<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\ValueObjects;

use InvalidArgumentException;

/**
 * Payment terms metadata, including a Skonto (cash discount) agreement.
 *
 * Skonto is a conditional, future reduction. Under §17 Abs. 1 UStG the taxable
 * base only changes when the Skonto is actually taken (Inanspruchnahme); until
 * then the invoice's Bemessungsgrundlage and stated VAT remain the full amounts.
 * Therefore this object carries Skonto purely as metadata and NEVER reduces the
 * document totals — see {@see \JohnWink\GobdInvoice\Tax\GroupedDocumentTotalsCalculator}.
 *
 * The exact structured BT-20 encoding (the `#SKONTO#TAGE=..#PROZENT=..#`
 * convention) is a KoSIT/XRechnung-CIUS convention, not codified in EN 16931,
 * and is intentionally NOT emitted here: it is verified and rendered by the
 * e-invoice exporter (M5) against the targeted XRechnung version.
 *
 * See docs/research/06-money-tax-and-rounding.md (Section 6, REQ-15/REQ-16).
 */
final readonly class PaymentTerms
{
    public function __construct(
        public ?int $netDays = null,
        public ?string $skontoPercentage = null,
        public ?int $skontoDays = null,
        public ?string $note = null,
    ) {
        throw_if($netDays !== null && $netDays < 0, InvalidArgumentException::class, 'netDays must not be negative.');
        throw_if($skontoDays !== null && $skontoDays < 0, InvalidArgumentException::class, 'skontoDays must not be negative.');
        throw_if($skontoPercentage !== null && ! is_numeric($skontoPercentage), InvalidArgumentException::class, "skontoPercentage must be a numeric string, got [{$skontoPercentage}].");
    }

    /**
     * Whether a complete Skonto agreement (percentage AND deadline) is present.
     */
    public function hasSkonto(): bool
    {
        return $this->skontoPercentage !== null && $this->skontoDays !== null;
    }
}
