<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Export\Datev;

use JohnWink\GobdInvoice\Contracts\DatevAccountResolver;
use JohnWink\GobdInvoice\Enums\TaxCategory;
use JohnWink\GobdInvoice\Exceptions\DatevExportException;
use JohnWink\GobdInvoice\Models\Document;
use JohnWink\GobdInvoice\ValueObjects\TaxRate;

/**
 * The default {@see DatevAccountResolver}: a single collective debtor account
 * and a per-VAT-group revenue-account map, both read from config. VAT groups are
 * keyed by the canonical {@see TaxRate::groupKey()} form (e.g. "S:19", "S:7",
 * "E:0"), so equivalent rate spellings ("19", "19.0", "19.00") resolve alike.
 * A host needing per-customer debtors binds its own resolver instead.
 */
final class ConfigDatevAccountResolver implements DatevAccountResolver
{
    public function debtorAccount(Document $document): int
    {
        $account = config('gobd-invoice.datev.debtor_account');

        if (! is_int($account)) {
            throw DatevExportException::missingDebtorAccount();
        }

        return $account;
    }

    public function revenueAccount(Document $document, string $category, string $rate): DatevAccount
    {
        $groupKey = new TaxRate($rate, TaxCategory::from($category))->groupKey();

        $map = config('gobd-invoice.datev.revenue_accounts', []);

        if (! is_array($map) || ! array_key_exists($groupKey, $map)) {
            throw DatevExportException::unmappedRevenueAccount($groupKey);
        }

        $entry = $map[$groupKey];

        // Shorthand: a bare integer is the account with no BU key (automatic account).
        if (is_int($entry)) {
            return new DatevAccount($entry);
        }

        if (! is_array($entry) || ! isset($entry['account']) || ! is_int($entry['account'])) {
            throw DatevExportException::unmappedRevenueAccount($groupKey);
        }

        $buSchluessel = $entry['bu'] ?? null;

        return new DatevAccount(
            $entry['account'],
            is_string($buSchluessel) && $buSchluessel !== '' ? $buSchluessel : null,
        );
    }
}
