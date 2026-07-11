<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\ValueObjects;

use DateTimeInterface;
use JohnWink\GobdInvoice\Enums\DebtorType;

/**
 * Inputs for assessing a dunning notice (Mahnung).
 *
 * Statutory §288 default interest is opt-in per notice: a goodwill reminder
 * (Kulanz) sets `withInterest: false`, which also switches the €40 flat fee off
 * by default — so a bare reminder demands only the principal (plus any explicit
 * `dunningFeeMinor`). When interest IS charged, `interestFrom` (Verzugsbeginn)
 * and `interestTo` (valuation date) are required.
 */
final readonly class DunningOptions
{
    /**
     * @param  DebtorType  $debtorType  drives the §288 surcharge and fee eligibility
     * @param  DateTimeInterface|null  $interestFrom  Verzugsbeginn — interest accrues from the day AFTER this (§187 Abs. 1)
     * @param  DateTimeInterface|null  $interestTo  valuation date — interest accrues up to and including this day
     * @param  bool  $withInterest  false = goodwill reminder without statutory interest (Kulanz)
     * @param  bool|null  $withLatePaymentFee  null = follow `withInterest` (capped: consumers never owe it, §288 Abs. 5)
     * @param  int  $dunningFeeMinor  an optional non-statutory dunning fee (Mahngebühr) in minor units
     * @param  int  $level  the dunning level (Mahnstufe: 1., 2., 3. Mahnung)
     */
    public function __construct(
        public DebtorType $debtorType,
        public ?DateTimeInterface $interestFrom = null,
        public ?DateTimeInterface $interestTo = null,
        public bool $withInterest = true,
        public ?bool $withLatePaymentFee = null,
        public int $dunningFeeMinor = 0,
        public int $level = 1,
    ) {}
}
