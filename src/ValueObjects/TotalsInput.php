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
    /** The VAT accounting currency for German VAT (§16 Abs. 6 UStG / REQ-17). */
    private const string ACCOUNTING_CURRENCY = 'EUR';

    /**
     * @param  array<int, TaxableLine>  $lines
     * @param  array<int, AllowanceCharge>  $documentAllowancesCharges
     * @param  ExchangeRate|null  $accountingRate  rate to the VAT accounting currency (EUR), supplied when the invoice currency is non-EUR so the total VAT is also expressed in EUR (BT-111)
     */
    public function __construct(
        public array $lines,
        public array $documentAllowancesCharges = [],
        public ?Money $paidAmount = null,
        public ?PaymentTerms $paymentTerms = null,
        public string $currency = 'EUR',
        public ?ExchangeRate $accountingRate = null,
    ) {
        // An AllowanceCharge is a TaxableLine (so it can be grouped), but a
        // document-level adjustment placed in $lines would be miscounted into
        // BT-106 instead of BT-107/BT-108. Reject the mix-up at construction.
        foreach ($lines as $line) {
            throw_if($line instanceof AllowanceCharge, InvalidArgumentException::class, 'Document-level allowances/charges must be passed as $documentAllowancesCharges, not as $lines.');
        }

        // EN 16931 emits the VAT total in the accounting currency (BT-111) iff the
        // invoice currency differs from it. Couple the two so neither a non-EUR
        // invoice silently omits BT-111 nor a EUR invoice emits a spurious one.
        if ($accountingRate instanceof ExchangeRate) {
            throw_if($currency === self::ACCOUNTING_CURRENCY, InvalidArgumentException::class, 'An accounting rate must not be supplied when the invoice currency is already the accounting currency ('.self::ACCOUNTING_CURRENCY.').');
            throw_if($accountingRate->baseCurrency !== $currency, InvalidArgumentException::class, "The accounting rate base [{$accountingRate->baseCurrency}] must match the invoice currency [{$currency}].");
            throw_if($accountingRate->quoteCurrency !== self::ACCOUNTING_CURRENCY, InvalidArgumentException::class, 'The accounting rate must convert to the VAT accounting currency ('.self::ACCOUNTING_CURRENCY.'), got ['.$accountingRate->quoteCurrency.'].');
        } else {
            throw_if($currency !== self::ACCOUNTING_CURRENCY, InvalidArgumentException::class, 'A non-'.self::ACCOUNTING_CURRENCY." invoice currency [{$currency}] requires an accounting rate to express the VAT total in ".self::ACCOUNTING_CURRENCY.' (BT-111).');
        }
    }
}
