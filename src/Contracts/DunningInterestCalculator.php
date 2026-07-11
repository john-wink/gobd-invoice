<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Contracts;

use JohnWink\GobdInvoice\ValueObjects\DunningAssessment;
use JohnWink\GobdInvoice\ValueObjects\DunningOptions;
use JohnWink\GobdInvoice\ValueObjects\Money;

/**
 * Assesses a dunning notice: the §288 BGB default interest on an overdue
 * principal (or none, for a goodwill reminder), the §288 Abs. 5 flat fee and any
 * non-statutory dunning fee. Kept out of the immutable tax record — a dunning
 * notice is a business process, not a tax document.
 */
interface DunningInterestCalculator
{
    public function assess(Money $money, DunningOptions $dunningOptions): DunningAssessment;
}
