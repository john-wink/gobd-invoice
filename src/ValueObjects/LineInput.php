<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\ValueObjects;

use JohnWink\GobdInvoice\Contracts\TaxableLine;
use JohnWink\GobdInvoice\Enums\PriceMode;

/**
 * An authored document line. It resolves its EN 16931 line net amount (BT-131)
 * from the unit price, quantity, price mode and any line-level allowances/
 * charges, so the totals calculator can treat it as a plain {@see TaxableLine}.
 *
 * BT-131 = `priceMode.lineNet(unitPrice, quantity) − Σ line allowances + Σ line
 * charges`, rounded to whole minor units (2 dp). Line-level allowances/charges
 * are net amounts (EN 16931 BT-136/BT-141) and are applied after the net base is
 * derived from the price mode.
 *
 * KNOWN LIMITATION (REQ-3, deferred): the unit price is a 2-dp {@see Money}, so
 * sub-cent unit prices (e.g. €1.859/l fuel, ct/kWh energy) cannot be authored —
 * `Money::fromDecimal()` rejects them loudly rather than truncating silently.
 * The research note's second precision tier (4+-dp unit prices, DECIMAL(15,4))
 * is a separate future change to {@see Money}.
 *
 * See docs/research/06-money-tax-and-rounding.md (Sections 1/2, REQ-2/REQ-3, 5, 6).
 */
final readonly class LineInput implements TaxableLine
{
    /**
     * @param  array<int, AllowanceCharge>  $allowancesCharges  line-level (net) allowances and charges
     */
    public function __construct(
        public Money $unitPrice,
        public string $quantity,
        public TaxRate $taxRate,
        public PriceMode $priceMode = PriceMode::Net,
        public array $allowancesCharges = [],
        public ?string $description = null,
        public ?string $unit = null,
    ) {}

    public function netAmount(): Money
    {
        $net = $this->priceMode->lineNet($this->unitPrice, $this->quantity, $this->taxRate);

        foreach ($this->allowancesCharges as $allowanceCharge) {
            $net = $net->plus($allowanceCharge->netAmount());
        }

        return $net;
    }

    public function taxRate(): TaxRate
    {
        return $this->taxRate;
    }
}
