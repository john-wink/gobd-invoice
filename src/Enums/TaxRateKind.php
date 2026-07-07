<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Enums;

/**
 * The two date-dependent German VAT rates under §12 UStG. Both map to EN 16931
 * category `S` (the percentage lives in BT-119, not the category); the applicable
 * percentage for a given Leistungszeitpunkt is resolved by a
 * {@see \JohnWink\GobdInvoice\Contracts\TaxRateResolver}.
 *
 * Structural 0 % cases (Z/E/K/G/AE/O) are NOT date-driven and are modelled
 * directly on {@see \JohnWink\GobdInvoice\ValueObjects\TaxRate}.
 */
enum TaxRateKind: string
{
    case Standard = 'standard'; // Regelsteuersatz, §12 Abs. 1 UStG
    case Reduced = 'reduced';   // ermäßigter Satz, §12 Abs. 2 UStG
}
