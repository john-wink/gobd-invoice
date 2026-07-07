<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Exceptions;

use JohnWink\GobdInvoice\Enums\DocumentStatus;

/**
 * Thrown when a document is asked to move to a status that its current status
 * does not permit (see {@see DocumentStatus::allowedTransitions()}).
 */
final class InvalidStatusTransitionException extends GobdInvoiceException
{
    public static function between(DocumentStatus $from, DocumentStatus $to): self
    {
        return new self("Cannot transition document from [{$from->value}] to [{$to->value}].");
    }
}
