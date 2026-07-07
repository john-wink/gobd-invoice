<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Enums;

/**
 * Document lifecycle states.
 *
 * The state machine and its transitions are documented in
 * docs/research/05-document-types-and-lifecycle.md.
 */
enum DocumentStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';
    case Sent = 'sent';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';

    /**
     * Whether the document has left the editable draft phase. Finalization
     * (Festschreibung) assigns the number and snapshots/hashes the content.
     */
    public function isFinalized(): bool
    {
        return $this !== self::Draft;
    }

    public function isCancelled(): bool
    {
        return $this === self::Cancelled;
    }

    /**
     * The status values this status may transition into.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Finalized, self::Cancelled],
            self::Finalized => [self::Sent, self::PartiallyPaid, self::Paid, self::Cancelled],
            self::Sent => [self::PartiallyPaid, self::Paid, self::Overdue, self::Cancelled],
            self::PartiallyPaid => [self::Paid, self::Overdue, self::Cancelled],
            self::Overdue => [self::PartiallyPaid, self::Paid, self::Cancelled],
            self::Paid, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }
}
