<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Exceptions;

use JohnWink\GobdInvoice\Contracts\DatevAccountResolver;

/**
 * Raised when a DATEV EXTF export cannot be produced correctly — e.g. a VAT
 * group or the debtor has no configured account mapping. The export fails loud
 * rather than emitting a booking batch an accountant cannot import.
 */
final class DatevExportException extends GobdInvoiceException
{
    public static function missingDebtorAccount(): self
    {
        return new self(
            'No DATEV debtor account configured. Set `gobd-invoice.datev.debtor_account` '
            .'or bind a custom '.DatevAccountResolver::class.'.'
        );
    }

    public static function unmappedRevenueAccount(string $groupKey): self
    {
        return new self(
            "No DATEV revenue account mapped for VAT group [{$groupKey}]. Add it to "
            .'`gobd-invoice.datev.revenue_accounts` (keyed by "<category>:<rate>", e.g. "S:19").'
        );
    }
}
