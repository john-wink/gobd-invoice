<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Iks;

use JohnWink\GobdInvoice\Contracts\SegregationPolicy;
use JohnWink\GobdInvoice\Exceptions\IksViolationException;
use JohnWink\GobdInvoice\Models\Document;

/**
 * A ready-made four-eyes {@see SegregationPolicy} (Vier-Augen-Prinzip): the actor
 * recorded as a document's creator (`created_by`) may not also finalize or cancel
 * it — a second person must perform the sensitive step. The check only bites when
 * both the creator and the acting actor are known; an anonymous context (null
 * actor) is not blocked, so hosts that do not identify actors are unaffected.
 */
final readonly class FourEyesSegregationPolicy implements SegregationPolicy
{
    public function assertCanFinalize(Document $document, ?string $actor): void
    {
        if ($this->sameActor($document, $actor)) {
            throw IksViolationException::sameActorFinalize($document->number);
        }
    }

    public function assertCanCancel(Document $document, ?string $actor): void
    {
        if ($this->sameActor($document, $actor)) {
            throw IksViolationException::sameActorCancel($document->number);
        }
    }

    private function sameActor(Document $document, ?string $actor): bool
    {
        return $actor !== null
            && $document->created_by !== null
            && $document->created_by === $actor;
    }
}
