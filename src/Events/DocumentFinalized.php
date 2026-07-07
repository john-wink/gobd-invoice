<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Events;

use JohnWink\GobdInvoice\Models\Document;

final readonly class DocumentFinalized
{
    public function __construct(
        public Document $document,
    ) {}
}
