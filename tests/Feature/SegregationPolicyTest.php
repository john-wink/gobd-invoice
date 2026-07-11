<?php

declare(strict_types=1);

use JohnWink\GobdInvoice\Exceptions\IksViolationException;
use JohnWink\GobdInvoice\Iks\FourEyesSegregationPolicy;
use JohnWink\GobdInvoice\Iks\PermissiveSegregationPolicy;
use JohnWink\GobdInvoice\Models\Document;

function documentCreatedBy(?string $creator): Document
{
    $document = new Document;
    $document->created_by = $creator;
    $document->number = 'rechnung-2026-00001';

    return $document;
}

it('permissive policy never blocks', function (): void {
    $policy = new PermissiveSegregationPolicy;
    $document = documentCreatedBy('alice');

    $policy->assertCanFinalize($document, 'alice');
    $policy->assertCanCancel($document, 'alice');

    expect(true)->toBeTrue(); // reached here = no veto
});

it('four-eyes blocks the creator from finalizing or cancelling their own document', function (): void {
    $policy = new FourEyesSegregationPolicy;
    $document = documentCreatedBy('alice');

    expect(fn () => $policy->assertCanFinalize($document, 'alice'))->toThrow(IksViolationException::class)
        ->and(fn () => $policy->assertCanCancel($document, 'alice'))->toThrow(IksViolationException::class);
});

it('four-eyes allows a different actor to finalize or cancel', function (): void {
    $policy = new FourEyesSegregationPolicy;
    $document = documentCreatedBy('alice');

    $policy->assertCanFinalize($document, 'bob');
    $policy->assertCanCancel($document, 'bob');

    expect(true)->toBeTrue();
});

it('four-eyes does not block when the actor or the creator is unknown', function (): void {
    $policy = new FourEyesSegregationPolicy;

    // Unknown acting actor (anonymous context).
    $policy->assertCanFinalize(documentCreatedBy('alice'), null);
    // Unknown creator (e.g. legacy document without a recorded creator).
    $policy->assertCanFinalize(documentCreatedBy(null), 'alice');

    expect(true)->toBeTrue();
});
