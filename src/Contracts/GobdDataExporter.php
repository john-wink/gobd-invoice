<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Contracts;

use JohnWink\GobdInvoice\Models\Document;

/**
 * Exports finalized documents into a structured, machine-readable data set for
 * tax-audit data access (GoBD/GDPdU "Datenträgerüberlassung", §147 Abs. 6 AO,
 * access type Z3). The host supplies the documents (e.g. a date-range query);
 * the exporter serializes them into files the auditor's software can ingest.
 */
interface GobdDataExporter
{
    /**
     * @param  iterable<Document>  $documents
     * @return array<string, string> filename => file content
     */
    public function export(iterable $documents): array;
}
