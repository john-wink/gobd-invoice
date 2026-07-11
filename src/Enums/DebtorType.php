<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Enums;

/**
 * Whether a debtor is a consumer (Verbraucher) or a business, which drives the
 * §288 BGB default-interest surcharge and the €40 flat-fee eligibility:
 *
 * - Consumer-involved debt: Basiszinssatz + 5 percentage points (§288 Abs. 1);
 *   the €40 Verzugspauschale (§288 Abs. 5) does NOT apply.
 * - Business debt (kein Verbraucher beteiligt), Entgeltforderung: Basiszinssatz
 *   + 9 percentage points (§288 Abs. 2) and the €40 flat fee is claimable.
 *
 * The concrete point values and fee are configurable (`gobd-invoice.dunning.*`);
 * this enum only expresses which regime a debtor falls under.
 */
enum DebtorType: string
{
    case Consumer = 'consumer'; // Verbraucher — §288 Abs. 1
    case Business = 'business'; // Unternehmer / kein Verbraucher — §288 Abs. 2/5

    /**
     * Whether the §288 Abs. 5 flat late-payment fee (Verzugspauschale) may be
     * charged — only against a business debtor.
     */
    public function allowsLatePaymentFee(): bool
    {
        return $this === self::Business;
    }
}
