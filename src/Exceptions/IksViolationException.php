<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Exceptions;

/**
 * Raised when an internal-control (IKS) rule vetoes a lifecycle action — e.g.
 * the four-eyes principle (Funktionstrennung): the actor who created a document
 * may not also be the one who finalizes or cancels it.
 */
final class IksViolationException extends GobdInvoiceException
{
    public static function sameActorFinalize(?string $number): self
    {
        $ref = $number ?? 'draft';

        return new self("Segregation of duties: the creator of [{$ref}] may not also finalize it (four-eyes principle).");
    }

    public static function sameActorCancel(?string $number): self
    {
        $ref = $number ?? 'document';

        return new self("Segregation of duties: the creator of [{$ref}] may not also cancel it (four-eyes principle).");
    }
}
