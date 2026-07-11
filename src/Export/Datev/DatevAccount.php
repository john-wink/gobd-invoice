<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Export\Datev;

/**
 * A resolved DATEV posting target for one VAT group: the revenue account (the
 * booking "Gegenkonto") and, optionally, the Buchungs-/Steuerschlüssel (BU key)
 * that triggers the automatic VAT split.
 *
 * When the revenue account is an "Automatikkonto" (pre-linked to a VAT rate in
 * the chart of accounts, e.g. SKR03 8400 for 19%), the BU key is left null and
 * DATEV derives the tax itself. A non-automatic account carries an explicit key.
 */
final readonly class DatevAccount
{
    public function __construct(
        public int $account,
        public ?string $buSchluessel = null,
    ) {}
}
