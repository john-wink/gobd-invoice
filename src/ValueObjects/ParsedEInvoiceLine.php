<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\ValueObjects;

/**
 * One line of a parsed incoming e-invoice (EN 16931 BG-25). Read-only extract of
 * what the sender declared — the amounts are as received, not re-computed.
 */
final readonly class ParsedEInvoiceLine
{
    public function __construct(
        public string $id,           // BT-126 line identifier
        public string $name,         // BT-153 item name
        public string $quantity,     // BT-129 invoiced quantity (decimal string)
        public ?string $unitCode,    // BT-130 unit of measure
        public Money $lineNet,       // BT-131 line net amount
        public ?string $taxCategory, // BT-151 line VAT category code
        public ?string $taxRate,     // BT-152 line VAT rate (decimal string)
    ) {}

    /**
     * @return array{id: string, name: string, quantity: string, unit_code: ?string, line_net_minor: int, tax_category: ?string, tax_rate: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'quantity' => $this->quantity,
            'unit_code' => $this->unitCode,
            'line_net_minor' => $this->lineNet->minorUnits,
            'tax_category' => $this->taxCategory,
            'tax_rate' => $this->taxRate,
        ];
    }
}
