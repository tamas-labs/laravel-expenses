<?php

declare(strict_types=1);

use TamasLabs\ExpensesSchema\ExpensesSchema;

// Pinned on purpose: a schema release that moves the contract must fail here
// first, so that adopting it is a deliberate step.
it('depends on contract version 1.2', function (): void {
    expect(ExpensesSchema::VERSION)->toBe('1.2');
});

it('ships the currency resource and the sync protocol', function (): void {
    expect(ExpensesSchema::resources())->toContain('currency')
        ->and(ExpensesSchema::protocol())->toContain('push-request');
});

it('ships the schema and fixture directories', function (): void {
    expect(ExpensesSchema::directory())->toBeDirectory()
        ->and(ExpensesSchema::fixturesDirectory())->toBeDirectory();
});
