<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\ValueObjects;

/**
 * One VAT breakdown group of a parsed incoming e-invoice (EN 16931 BG-23).
 */
final readonly class ParsedEInvoiceTax
{
    public function __construct(
        public string $category,          // BT-118 VAT category code
        public ?string $rate,             // BT-119 VAT rate (decimal string)
        public Money $basis,              // BT-116 taxable amount
        public Money $tax,                // BT-117 tax amount
        public ?string $exemptionReason,  // BT-120 exemption reason text
    ) {}

    /**
     * @return array{category: string, rate: ?string, basis_minor: int, tax_minor: int, exemption_reason: ?string}
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'rate' => $this->rate,
            'basis_minor' => $this->basis->minorUnits,
            'tax_minor' => $this->tax->minorUnits,
            'exemption_reason' => $this->exemptionReason,
        ];
    }
}
