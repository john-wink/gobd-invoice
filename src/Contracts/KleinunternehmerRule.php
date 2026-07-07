<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Contracts;

use JohnWink\GobdInvoice\ValueObjects\KleinunternehmerAssessment;
use JohnWink\GobdInvoice\ValueObjects\Money;

/**
 * Assesses §19 UStG Kleinunternehmer status from the prior- and current-year
 * total turnover (Gesamtumsatz, measured net). Implementations encode the
 * statutory thresholds and the mid-year "Fallbeil" (exceeding the upper limit
 * ends the exemption immediately). See docs/research/06-money-tax-and-rounding.md.
 */
interface KleinunternehmerRule
{
    /**
     * @param  Money|null  $priorYearTurnover  the previous calendar year's total net turnover, or null in the year of commencement (Neugründung: no prior year — only the lower current-year limit applies)
     * @param  Money  $currentYearTurnover  the current calendar year's total net turnover, INCLUDING the transaction being assessed
     */
    public function assess(?Money $priorYearTurnover, Money $currentYearTurnover): KleinunternehmerAssessment;
}
