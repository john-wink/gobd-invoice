<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\ValueObjects;

/**
 * A document's complete monetary chain, mapped to EN 16931 Business Terms. Every
 * amount is a sum of already-rounded components (line nets rounded to 2 dp, VAT
 * rounded once per group); the document-level totals are therefore never
 * re-rounded, satisfying EN 16931's rule that "results from calculations
 * involving already rounded amounts are not subject to rounding" (REQ-10).
 *
 * | field           | BT     | meaning                                            |
 * |-----------------|--------|----------------------------------------------------|
 * | lineNetTotal    | BT-106 | Σ line net amounts (Σ BT-131)                      |
 * | allowanceTotal  | BT-107 | Σ document-level allowances                        |
 * | chargeTotal     | BT-108 | Σ document-level charges                           |
 * | netTotal        | BT-109 | total without VAT = BT-106 − BT-107 + BT-108       |
 * | vatTotal        | BT-110 | total VAT = Σ per-group BT-117                     |
 * | grossTotal      | BT-112 | total with VAT = BT-109 + BT-110                   |
 * | paidAmount      | BT-113 | amount already paid                                |
 * | roundingAmount  | BT-114 | payable rounding adjustment (normally 0.00)        |
 * | amountDue       | BT-115 | amount due = BT-112 − BT-113 + BT-114              |
 *
 * The per-group Steuerausweis (BG-23: BT-116 base, BT-119 rate, BT-117 tax) lives
 * in {@see self::taxBreakdown}. When the invoice currency is not the VAT
 * accounting currency (EUR for German VAT), the total VAT is additionally
 * expressed in the accounting currency as {@see self::vatAccountingTotal}
 * (BT-111), with the {@see self::accountingRate} retained for GoBD
 * reproducibility. See docs/research/06-money-tax-and-rounding.md (Sections 5, 7).
 */
final readonly class DocumentTotals
{
    public function __construct(
        public Money $lineNetTotal,
        public Money $allowanceTotal,
        public Money $chargeTotal,
        public Money $netTotal,
        public TaxBreakdown $taxBreakdown,
        public Money $vatTotal,
        public Money $grossTotal,
        public Money $paidAmount,
        public Money $roundingAmount,
        public Money $amountDue,
        public ?PaymentTerms $paymentTerms = null,
        public ?Money $vatAccountingTotal = null,
        public ?ExchangeRate $accountingRate = null,
        public ?Money $advancesNetTotal = null,
        public ?Money $advancesVatTotal = null,
    ) {}
}
