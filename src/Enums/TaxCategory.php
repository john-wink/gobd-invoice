<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Enums;

/**
 * VAT category codes — the UNCL5305 (UNTDID 5305) subset used by EN 16931
 * Business Term BT-118 (VAT category code).
 *
 * IMPORTANT: in Germany BOTH the 19% standard rate and the 7% reduced rate use
 * category code 'S' (Standard); the actual percentage is carried separately in
 * BT-119 by the {@see \JohnWink\GobdInvoice\ValueObjects\TaxRate} value object.
 * There is no distinct "reduced" category code. A §19 Kleinunternehmer invoice
 * uses category 'E' (Exempt).
 *
 * See docs/research/06-money-tax-and-rounding.md and
 * docs/research/08-package-architecture.md (B6).
 */
enum TaxCategory: string
{
    case Standard = 'S';        // standard (19%) AND reduced (7%); rate in BT-119
    case ZeroRated = 'Z';       // zero rated goods
    case Exempt = 'E';          // exempt — also used for §19 Kleinunternehmer
    case ReverseCharge = 'AE';  // VAT reverse charge (§13b UStG)
    case IntraCommunity = 'K';  // VAT-exempt intra-community supply
    case Export = 'G';          // free export item, tax not charged
    case OutOfScope = 'O';      // services outside scope of tax

    /**
     * Whether a positive VAT amount is computed for this category.
     * Only {@see self::Standard} yields VAT; every other category is 0.
     */
    public function isTaxed(): bool
    {
        return $this === self::Standard;
    }

    /**
     * The §-based note that ALWAYS accompanies this category, or null when the
     * note is context-dependent or none applies. Category {@see self::Exempt} is
     * intentionally null: an exemption's note depends on its legal basis (§19
     * Kleinunternehmer vs a §4 UStG exemption), so the §19 note is owned by
     * {@see \JohnWink\GobdInvoice\ValueObjects\KleinunternehmerAssessment}, not
     * derived from the bare category.
     */
    public function noteTranslationKey(): ?string
    {
        return match ($this) {
            self::ReverseCharge => 'gobd-invoice::gobd-invoice.notes.reverse_charge',
            default => null,
        };
    }
}
