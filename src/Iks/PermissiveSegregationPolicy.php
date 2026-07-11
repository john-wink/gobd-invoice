<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Iks;

use JohnWink\GobdInvoice\Contracts\SegregationPolicy;
use JohnWink\GobdInvoice\Models\Document;

/**
 * The default {@see SegregationPolicy}: no restriction. Segregation of duties is
 * opt-in, so the out-of-the-box behaviour is unchanged; enable a stricter policy
 * (e.g. {@see FourEyesSegregationPolicy}) via `gobd-invoice.iks.segregation`.
 */
final readonly class PermissiveSegregationPolicy implements SegregationPolicy
{
    public function assertCanFinalize(Document $document, ?string $actor): void
    {
        // Intentionally unrestricted.
    }

    public function assertCanCancel(Document $document, ?string $actor): void
    {
        // Intentionally unrestricted.
    }
}
