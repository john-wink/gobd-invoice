<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Exceptions;

/**
 * Thrown when code attempts to mutate the tax-relevant content of a finalized
 * (festgeschrieben) document, which GoBD Unveränderbarkeit forbids.
 */
final class DocumentIsImmutableException extends GobdInvoiceException
{
    public static function forFinalizedDocument(string $number): self
    {
        return new self(
            "Document [{$number}] is finalized and immutable (GoBD Unveränderbarkeit). ".
            'Correct it by issuing a linked Storno + new document, never by editing.'
        );
    }
}
