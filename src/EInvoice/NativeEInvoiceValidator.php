<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\EInvoice;

use DOMDocument;
use horstoeko\zugferdublbridge\XmlConverterUblToCii;
use JohnWink\En16931\En16931Validator;
use JohnWink\En16931\ValidationResult;
use JohnWink\GobdInvoice\Contracts\EInvoiceValidator;
use JohnWink\GobdInvoice\Exceptions\GobdInvoiceException;

/**
 * Validates an e-invoice with the native, Java-free john-wink/en16931-php engine.
 * UBL input is bridged to CII first (the underlying validator reads CII), so both
 * syntaxes are accepted.
 */
final readonly class NativeEInvoiceValidator implements EInvoiceValidator
{
    /**
     * @var list<string>
     */
    private const array UBL_ROOT_NAMESPACES = [
        'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
        'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2',
    ];

    public function __construct(private En16931Validator $en16931Validator) {}

    public function validate(string $xml): ValidationResult
    {
        return $this->en16931Validator->validateCii($this->toCii($xml));
    }

    private function toCii(string $xml): string
    {
        return $this->isUbl($xml)
            ? XmlConverterUblToCii::fromString($xml)->convert()->saveXmlString()
            : $xml;
    }

    private function isUbl(string $xml): bool
    {
        $domDocument = new DOMDocument;

        throw_unless(@$domDocument->loadXML($xml), GobdInvoiceException::class, 'The e-invoice payload is not well-formed XML.');

        return in_array($domDocument->documentElement?->namespaceURI, self::UBL_ROOT_NAMESPACES, true);
    }
}
