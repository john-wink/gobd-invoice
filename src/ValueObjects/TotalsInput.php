<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\ValueObjects;

use InvalidArgumentException;
use JohnWink\GobdInvoice\Contracts\TaxableLine;

/**
 * The input to {@see \JohnWink\GobdInvoice\Contracts\DocumentTotalsCalculator}:
 * the document's taxable lines, its document-level allowances/charges, the
 * amount already paid, and optional payment terms.
 *
 * `paidAmount` and `documentAllowancesCharges` default to none, so a plain
 * net invoice is `new TotalsInput($lines)`.
 */
final readonly class TotalsInput
{
    /**
     * @param  array<int, TaxableLine>  $lines
     * @param  array<int, AllowanceCharge>  $documentAllowancesCharges
     */
    public function __construct(
        public array $lines,
        public array $documentAllowancesCharges = [],
        public ?Money $paidAmount = null,
        public ?PaymentTerms $paymentTerms = null,
        public string $currency = 'EUR',
    ) {
        // An AllowanceCharge is a TaxableLine (so it can be grouped), but a
        // document-level adjustment placed in $lines would be miscounted into
        // BT-106 instead of BT-107/BT-108. Reject the mix-up at construction.
        foreach ($lines as $line) {
            throw_if($line instanceof AllowanceCharge, InvalidArgumentException::class, 'Document-level allowances/charges must be passed as $documentAllowancesCharges, not as $lines.');
        }
    }
}
