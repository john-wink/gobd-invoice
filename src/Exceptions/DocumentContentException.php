<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Exceptions;

/**
 * Thrown when a document fails §14 UStG mandatory-content validation at
 * finalization. Finalization is fail-closed: an invoice missing a required
 * legal field must never be festgeschrieben. See
 * docs/research/02-legal-invoice-content.md.
 */
final class DocumentContentException extends GobdInvoiceException
{
    /**
     * @param  list<string>  $violations  machine-readable keys of the missing/invalid fields
     */
    public function __construct(
        string $message,
        public readonly array $violations = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  list<string>  $violations
     */
    public static function withViolations(?string $number, array $violations): self
    {
        $label = $number ?? 'draft';

        return new self(
            "Document [{$label}] cannot be finalized — it violates §14 UStG mandatory content: ".
            implode(', ', $violations).'.',
            $violations,
        );
    }
}
