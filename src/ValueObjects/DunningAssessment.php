<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\ValueObjects;

use JohnWink\GobdInvoice\Enums\DebtorType;

/**
 * The result of assessing a dunning notice: the overdue principal, the §288
 * default interest (zero for a goodwill reminder), the §288 Abs. 5 flat fee, an
 * optional non-statutory dunning fee, and their sum — plus the per-period
 * interest breakdown. Money is never a float; every amount is a {@see Money}.
 *
 * Dunning is a business process, deliberately kept OUT of the immutable tax
 * record: this assessment is attached to a (non-tax) Mahnung as metadata, it
 * does not alter the dunned invoice.
 */
final readonly class DunningAssessment
{
    /**
     * @param  list<DunningInterestPeriod>  $interestPeriods
     */
    public function __construct(
        public Money $principal,
        public Money $interest,
        public Money $latePaymentFee,
        public Money $dunningFee,
        public DebtorType $debtorType,
        public int $level,
        public array $interestPeriods = [],
    ) {}

    /** The total demanded: principal + interest + statutory fee + dunning fee. */
    public function total(): Money
    {
        return $this->principal
            ->plus($this->interest)
            ->plus($this->latePaymentFee)
            ->plus($this->dunningFee);
    }

    /**
     * A JSON-serializable snapshot for storing on the Mahnung's metadata.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'debtor_type' => $this->debtorType->value,
            'level' => $this->level,
            'currency' => $this->principal->currency,
            'principal_minor' => $this->principal->minorUnits,
            'interest_minor' => $this->interest->minorUnits,
            'late_payment_fee_minor' => $this->latePaymentFee->minorUnits,
            'dunning_fee_minor' => $this->dunningFee->minorUnits,
            'total_minor' => $this->total()->minorUnits,
            'interest_periods' => array_map(static fn (DunningInterestPeriod $dunningInterestPeriod): array => [
                'from' => $dunningInterestPeriod->from,
                'to' => $dunningInterestPeriod->to,
                'days' => $dunningInterestPeriod->days,
                'year_days' => $dunningInterestPeriod->yearDays,
                'annual_rate_percent' => $dunningInterestPeriod->annualRatePercent,
                'amount_minor' => $dunningInterestPeriod->amount->minorUnits,
            ], $this->interestPeriods),
        ];
    }
}
