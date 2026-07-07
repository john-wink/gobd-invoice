<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Audit;

/**
 * Produces a deterministic content hash over a document snapshot. Keys are
 * sorted recursively so the hash is stable regardless of array order; re-hashing
 * on read detects tampering (GoBD Unveränderbarkeit). See
 * docs/research/01-gobd-compliance.md.
 */
final readonly class ContentHasher
{
    public function __construct(
        private string $algorithm = 'sha256',
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function hash(array $payload): string
    {
        return hash($this->algorithm, $this->canonicalize($payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function canonicalize(array $payload): string
    {
        self::ksortRecursive($payload);

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<array-key, mixed>  $array
     */
    private static function ksortRecursive(array &$array): void
    {
        ksort($array);

        foreach ($array as &$value) {
            if (is_array($value)) {
                self::ksortRecursive($value);
            }
        }
    }
}
