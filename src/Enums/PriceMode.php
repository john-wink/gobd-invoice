<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Enums;

use JohnWink\GobdInvoice\ValueObjects\Money;
use JohnWink\GobdInvoice\ValueObjects\TaxRate;

/**
 * How a line's authored unit price is interpreted.
 *
 * - {@see self::Net} (Nettorechnung, typical B2B): the price is the net base;
 *   VAT is added on top.
 * - {@see self::Gross} (Bruttorechnung, typical B2C/retail): the price already
 *   includes VAT; the net base is extracted (`net = gross / (1 + rate)`).
 *
 * The two modes legitimately differ by cents on the same goods ("kein
 * Rechenfehler"); because a finalized document is immutable under GoBD, the mode
 * an amount was authored in must be stored, not re-derived. EN 16931 always
 * transports the net base regardless of authoring mode.
 *
 * See docs/research/06-money-tax-and-rounding.md (Section 2, REQ-4/REQ-5).
 */
enum PriceMode: string
{
    case Net = 'net';
    case Gross = 'gross';

    /**
     * The net taxable base (BT-131) for `quantity` units of the given unit price.
     * In {@see self::Net} mode the price is already net; in {@see self::Gross}
     * mode the VAT contained for the line's rate is extracted. Either way the net
     * is rounded to whole minor units exactly once — the gross line extension is
     * kept exact until that single rounding (REQ-9/REQ-10).
     */
    public function lineNet(Money $money, string $quantity, TaxRate $taxRate): Money
    {
        return match ($this) {
            self::Net => $money->multipliedBy($quantity),
            self::Gross => $money->netFromGross($taxRate->percent(), $quantity),
        };
    }
}
