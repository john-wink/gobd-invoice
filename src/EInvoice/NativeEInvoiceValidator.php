<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\EInvoice;

use JohnWink\En16931\En16931Validator;
use JohnWink\En16931\ValidationResult;
use JohnWink\GobdInvoice\Contracts\EInvoiceValidator;

/**
 * Validates an e-invoice with the native, Java-free john-wink/en16931-php engine.
 * Both CII and UBL syntax are accepted; the engine auto-detects which.
 */
final readonly class NativeEInvoiceValidator implements EInvoiceValidator
{
    public function __construct(private En16931Validator $en16931Validator) {}

    public function validate(string $xml): ValidationResult
    {
        return $this->en16931Validator->validate($xml);
    }
}
