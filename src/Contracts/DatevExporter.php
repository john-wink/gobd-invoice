<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Contracts;

use JohnWink\GobdInvoice\Export\Datev\DatevExportOptions;
use JohnWink\GobdInvoice\Models\Document;

/**
 * Exports finalized documents as a DATEV "EXTF" Buchungsstapel (booking batch)
 * — the ASCII interface a German tax advisor imports into DATEV. The host
 * supplies the documents (e.g. a date-range query) and the export metadata; the
 * exporter produces the byte-correct file content (Windows-1252, CRLF).
 */
interface DatevExporter
{
    /**
     * @param  iterable<Document>  $documents
     * @return string the EXTF file content, encoded as Windows-1252
     */
    public function export(iterable $documents, DatevExportOptions $datevExportOptions): string;
}
