<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Audit\ContentHasher;

covers(ContentHasher::class);

/**
 * The content hash is the GoBD Unveränderbarkeit anchor: it must be stable
 * regardless of array key order (so re-hashing on read is order-independent)
 * and its on-the-wire canonical form is a compatibility contract — a stored
 * hash produced today must still verify tomorrow. These tests pin both.
 */
it('pins the canonical wire format with a golden hash', function (): void {
    // Deliberately unsorted at the top level AND inside a nested array, and
    // carrying a non-ASCII character plus a slash — so this single assertion
    // fails if key sorting (top-level or recursive) is dropped or the
    // JSON_UNESCAPED_* flags change.
    $payload = ['note' => 'Straße/2', 'amount' => 100, 'z' => ['b' => 1, 'a' => 2]];

    expect((new ContentHasher)->hash($payload))
        ->toBe('f2937c385652b71cbbf2b992da45b219f6cf009836065c46419df91253accefb');
});

it('is independent of top-level key order', function (): void {
    $hasher = new ContentHasher;

    expect($hasher->hash(['b' => 1, 'a' => 2]))
        ->toBe($hasher->hash(['a' => 2, 'b' => 1]));
});

it('is independent of nested key order (recursive sort)', function (): void {
    $hasher = new ContentHasher;

    expect($hasher->hash(['outer' => ['b' => 1, 'a' => 2]]))
        ->toBe($hasher->hash(['outer' => ['a' => 2, 'b' => 1]]));
});

it('escapes neither unicode nor slashes in the canonical form', function (): void {
    $hasher = new ContentHasher;

    // If the JSON flags were altered, 'ü' would become 'ü' and '/' would
    // become '\/', changing the hash away from the unescaped-string hash.
    expect($hasher->hash(['x' => 'ü/z']))
        ->toBe(hash('sha256', '{"x":"ü/z"}'));
});

it('produces different hashes for different content', function (): void {
    $hasher = new ContentHasher;

    expect($hasher->hash(['a' => 1]))->not->toBe($hasher->hash(['a' => 2]));
});

it('honours a non-default algorithm', function (): void {
    expect((new ContentHasher('sha512'))->hash(['a' => 1]))
        ->toBe(hash('sha512', '{"a":1}'))
        ->and(mb_strlen((new ContentHasher('sha512'))->hash(['a' => 1])))->toBe(128);
});
