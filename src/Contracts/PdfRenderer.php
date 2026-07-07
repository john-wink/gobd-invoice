<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Contracts;

/**
 * Renders a document into PDF/A-3 bytes from a Blade theme. The e-invoice driver
 * then embeds the EN 16931 XML into the produced PDF/A-3 (hybrid ZUGFeRD).
 * Implemented in milestone M4 — see docs/ROADMAP.md.
 */
interface PdfRenderer
{
    public function render(InvoiceDocument $invoiceDocument, string $theme = 'default'): string;
}
