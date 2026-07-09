<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Contracts;

use JohnWink\En16931\ValidationResult;

/**
 * Validates an e-invoice payload (CII or UBL) against the EN 16931 / XRechnung
 * business rules. Backed by the dependency-free, Java-free john-wink/en16931-php
 * validator (no KoSIT jar, no JRE).
 */
interface EInvoiceValidator
{
    public function validate(string $xml): ValidationResult;
}
