<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\ValueObjects;

use JohnWink\GobdInvoice\Enums\TaxCategory;

/**
 * The result of assessing §19 UStG Kleinunternehmer status for a transaction.
 *
 * Under the reform in force since 2025-01-01 the exemption applies when the
 * prior calendar year's total turnover did not exceed the lower limit AND the
 * current calendar year's turnover does not exceed the upper limit (both are
 * "nicht überschritten" — a turnover exactly at a limit is still within it).
 *
 * Exceeding the upper limit ends the exemption immediately (the "Fallbeil",
 * UStAE 19.1 Abs. 2): the transaction that crosses it is taxed under the
 * standard regime in FULL (not just the part above the limit), triggered on
 * Vereinnahmung (Ist-Prinzip, §19 Abs. 2 Gesamtumsatz). A caller therefore
 * passes the current-year turnover INCLUDING the transaction being assessed;
 * when that pushes it over the limit, this assessment is not exempt and the
 * whole line is standard-rated. See docs/research/06-money-tax-and-rounding.md
 * (REQ-20) and BMF 2025-03-18.
 *
 * Scope: this is a TURNOVER-eligibility determination only. A §19 Abs. 3 UStG
 * waiver (Verzicht / option to standard taxation, binding for 5 years) is
 * host-side business state that OVERRIDES eligibility — an eligible business
 * that has opted out charges VAT. The caller must apply that election before
 * treating {@see self::exempt} as the invoice's tax status.
 */
final readonly class KleinunternehmerAssessment
{
    /** The §19 exemption note required by §34a S. 1 Nr. 5 UStDV. */
    private const string EXEMPTION_NOTE_KEY = 'gobd-invoice::gobd-invoice.notes.kleinunternehmer';

    public function __construct(
        public bool $exempt,
        public bool $priorYearLimitExceeded,
        public bool $currentYearLimitExceeded,
    ) {}

    /**
     * The EN 16931 VAT category to apply: {@see TaxCategory::Exempt} (E) for a
     * Kleinunternehmer supply, otherwise the standard category (S).
     */
    public function taxCategory(): TaxCategory
    {
        return $this->exempt ? TaxCategory::Exempt : TaxCategory::Standard;
    }

    /**
     * The mandatory §19 exemption note to show on the invoice, or null when the
     * transaction is taxed under the standard regime. Owned here (not derived
     * from category E, which also covers non-§19 exemptions).
     */
    public function noteTranslationKey(): ?string
    {
        return $this->exempt ? self::EXEMPTION_NOTE_KEY : null;
    }
}
