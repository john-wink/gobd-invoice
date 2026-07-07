<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Contracts;

use JohnWink\GobdInvoice\ValueObjects\Money;
use JohnWink\GobdInvoice\ValueObjects\TaxRate;

/**
 * A single line that contributes to the VAT breakdown. The line's net amount is
 * its already-computed line total (unit price × quantity − line discount).
 */
interface TaxableLine
{
    public function netAmount(): Money;

    public function taxRate(): TaxRate;
}
