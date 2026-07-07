<?php

declare(strict_types=1);

arch('the whole package uses strict types')
    ->expect('JohnWink\GobdInvoice')
    ->toUseStrictTypes();

arch('no debugging helpers are left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

arch('value objects are immutable')
    ->expect('JohnWink\GobdInvoice\ValueObjects')
    ->toBeReadonly();

arch('enums are enums')
    ->expect('JohnWink\GobdInvoice\Enums')
    ->toBeEnums();

arch('contracts are interfaces')
    ->expect('JohnWink\GobdInvoice\Contracts')
    ->toBeInterfaces();

arch('exceptions are throwable')
    ->expect('JohnWink\GobdInvoice\Exceptions')
    ->toImplement('Throwable');
