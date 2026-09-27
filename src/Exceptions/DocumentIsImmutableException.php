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

    /**
     * The document of a line cannot be read, so it counts as finalized: a
     * guard that cannot see the document refuses rather than let the change
     * through.
     */
    public static function forUnreadableDocument(int|string $documentId): self
    {
        return new self(
            "Document [{$documentId}] of this line cannot be read, so the line is treated as immutable (GoBD Unveränderbarkeit). ".
            'A line changes only while its document is a draft that this session can see.'
        );
    }
}
