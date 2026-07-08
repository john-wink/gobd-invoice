<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\ValueObjects;

/**
 * A framework-agnostic, read-only extract of an incoming EN 16931 e-invoice
 * (CII or UBL). This is the output of the receive/parse path — the values are
 * as the sender declared them; the package does not re-compute or trust them.
 * The host decides what to persist. See docs/research/03-e-invoicing.md.
 */
final readonly class ParsedEInvoice
{
    /**
     * @param  list<ParsedEInvoiceLine>  $lines
     * @param  list<ParsedEInvoiceTax>  $taxBreakdown
     * @param  list<string>  $notes
     */
    public function __construct(
        public string $number,             // BT-1 invoice number
        public string $typeCode,           // BT-3 invoice type code
        public ?string $issueDate,         // BT-2 issue date (Y-m-d)
        public string $currency,           // BT-5 invoice currency
        public Party $seller,              // BG-4 seller
        public Party $buyer,               // BG-7 buyer
        public Money $grandTotal,          // BT-112 total incl. VAT
        public Money $payableAmount,       // BT-115 amount due
        public Money $taxBasisTotal,       // BT-109 total without VAT
        public Money $taxTotal,            // BT-110 total VAT
        public array $lines,
        public array $taxBreakdown,
        public array $notes = [],
        public ?string $buyerReference = null, // BT-10 (Leitweg-ID)
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'type_code' => $this->typeCode,
            'issue_date' => $this->issueDate,
            'currency' => $this->currency,
            'seller' => $this->seller->toArray(),
            'buyer' => $this->buyer->toArray(),
            'grand_total_minor' => $this->grandTotal->minorUnits,
            'payable_amount_minor' => $this->payableAmount->minorUnits,
            'tax_basis_total_minor' => $this->taxBasisTotal->minorUnits,
            'tax_total_minor' => $this->taxTotal->minorUnits,
            'lines' => array_map(static fn (ParsedEInvoiceLine $parsedEInvoiceLine): array => $parsedEInvoiceLine->toArray(), $this->lines),
            'tax_breakdown' => array_map(static fn (ParsedEInvoiceTax $parsedEInvoiceTax): array => $parsedEInvoiceTax->toArray(), $this->taxBreakdown),
            'notes' => $this->notes,
            'buyer_reference' => $this->buyerReference,
        ];
    }
}
