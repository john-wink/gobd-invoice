<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Contracts;

use JohnWink\GobdInvoice\ValueObjects\ParsedEInvoice;

/**
 * Parses an incoming EN 16931 e-invoice payload (ZUGFeRD/Factur-X or XRechnung,
 * in CII or UBL syntax) into a structured, framework-agnostic value object.
 * Being able to receive and process e-invoices is a legal obligation in force
 * since 2025-01 (Wachstumschancengesetz). See docs/research/03-e-invoicing.md.
 */
interface EInvoiceReader
{
    /**
     * @param  string  $xml  the raw CII or UBL invoice XML
     */
    public function read(string $xml): ParsedEInvoice;
}
