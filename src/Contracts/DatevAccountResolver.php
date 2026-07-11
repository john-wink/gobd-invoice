<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Contracts;

use JohnWink\GobdInvoice\Export\Datev\DatevAccount;
use JohnWink\GobdInvoice\Models\Document;

/**
 * Resolves the DATEV chart-of-accounts targets for a document's bookings. The
 * chart of accounts (SKR03/SKR04, automatic-tax accounts, the collective vs
 * per-customer debtor decision) is client-specific, so the package never
 * hardcodes account numbers — a host supplies them via config or a custom
 * implementation.
 */
interface DatevAccountResolver
{
    /**
     * The debtor (accounts-receivable) account posted as "Konto" for the
     * document — a collective debtor or a per-customer personal account.
     */
    public function debtorAccount(Document $document): int;

    /**
     * The revenue account (and optional BU key) posted as "Gegenkonto" for one
     * VAT group of the document, identified by its EN 16931 category code
     * (e.g. "S", "AE") and rate (e.g. "19.0").
     */
    public function revenueAccount(Document $document, string $category, string $rate): DatevAccount;
}
