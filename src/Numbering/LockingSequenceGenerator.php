<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Numbering;

use JohnWink\GobdInvoice\Contracts\NumberSequenceGenerator;

/**
 * Race-safe, gapless sequence generator. The increment logic lives in
 * {@see LocksAndIncrementsSequence} so host applications can reuse it while
 * overriding the counter key or format (see {@see ResolvesSequenceKeyAndFormat}).
 */
final class LockingSequenceGenerator implements NumberSequenceGenerator
{
    use LocksAndIncrementsSequence;

    /**
     * Gapless: the increment must live inside the caller's finalize transaction so
     * a rolled-back finalize also rolls back the consumed number.
     */
    public function allocatesWithinTransaction(): bool
    {
        return true;
    }
}
