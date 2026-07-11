<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Export\Datev;

use DateTimeInterface;

/**
 * The metadata carried in the EXTF header row: the DATEV client identity
 * (Berater/Mandant), the fiscal year, the batch's date range and locking flag.
 * Account numbers are NOT here — those come from the {@see DatevAccountResolver}.
 */
final readonly class DatevExportOptions
{
    /**
     * @param  int  $berater  DATEV Beraternummer (tax-advisor number, ≤ 7 digits)
     * @param  int  $mandant  DATEV Mandantennummer (client number, ≤ 5 digits)
     * @param  DateTimeInterface  $fiscalYearStart  WJ-Beginn (fiscal-year start)
     * @param  int  $accountLength  Sachkontenlänge (G/L account digit length, 4–8)
     * @param  string  $description  batch label (Bezeichnung, ≤ 30 chars)
     * @param  string  $currency  ISO-4217 batch currency
     * @param  bool  $locked  Festschreibung: whether the imported batch is locked
     * @param  string  $chartOfAccounts  SKR id, e.g. "03" or "04" (empty = default)
     * @param  string  $origin  Herkunft-Kennzeichen (2-char producing-system id)
     * @param  string  $exportedBy  human/system that produced the export (≤ 25 chars)
     * @param  DateTimeInterface|null  $dateFrom  period start; derived from the documents when null
     * @param  DateTimeInterface|null  $dateTo  period end; derived from the documents when null
     * @param  DateTimeInterface|null  $createdAt  "erzeugt am" timestamp; defaults to now at export time
     */
    public function __construct(
        public int $berater,
        public int $mandant,
        public DateTimeInterface $fiscalYearStart,
        public int $accountLength = 4,
        public string $description = '',
        public string $currency = 'EUR',
        public bool $locked = true,
        public string $chartOfAccounts = '',
        public string $origin = '',
        public string $exportedBy = '',
        public ?DateTimeInterface $dateFrom = null,
        public ?DateTimeInterface $dateTo = null,
        public ?DateTimeInterface $createdAt = null,
    ) {}
}
