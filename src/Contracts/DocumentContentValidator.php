<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Contracts;

use JohnWink\GobdInvoice\Models\Document;

/**
 * Validates a document's §14 UStG mandatory content before finalization.
 * Implementations MUST throw
 * {@see \JohnWink\GobdInvoice\Exceptions\DocumentContentException} when a
 * required legal field is missing — finalization is fail-closed. See
 * docs/research/02-legal-invoice-content.md.
 */
interface DocumentContentValidator
{
    public function validate(Document $document): void;
}
