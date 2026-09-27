<?php

declare(strict_types=1);

use TamasLabs\LaravelExpenses\Sync\Cursor;
use TamasLabs\LaravelExpenses\Sync\ProtocolError;

// Spec 06, 3.3 and 4.6: the cursor's form and when it has expired.

it('starts from the beginning without a cursor', function (mixed $value): void {
    expect(Cursor::parse($value)->seq)->toBe(0);
})->with([null, '']);

it('reads back the cursors it writes', function (int $seq): void {
    $cursor = (string) Cursor::at($seq);

    expect($cursor)->toBe("s:{$seq}")
        ->and(Cursor::parse($cursor)->seq)->toBe($seq);
})->with([0, 1, 1842, PHP_INT_MAX]);

it('refuses what it would not write', function (mixed $value): void {
    expect(static fn () => Cursor::parse($value))->toThrow(ProtocolError::class, 'The cursor is not one the server issued.');
})->with(['1842', 's:', 's:-1', 's:01', 's:+1', 's:1.5', 's:1e3', ' s:1', 's:1 ', "s:1\n", 'S:1', 's:9223372036854775808', 'an array' => [['s:1']], 'an integer' => 1842]);

it('says it is malformed with a 422', function (): void {
    $error = null;

    try {
        Cursor::parse('x');
    } catch (ProtocolError $caught) {
        $error = $caught;
    }

    expect($error?->errorCode)->toBe('cursor_malformed')
        ->and($error?->status)->toBe(422);
});

it('expires below the pruning mark, but never at the start', function (): void {
    expect(Cursor::at(499)->isOlderThan(500))->toBeTrue()
        ->and(Cursor::at(500)->isOlderThan(500))->toBeFalse()
        ->and(Cursor::at(501)->isOlderThan(500))->toBeFalse()
        ->and(Cursor::start()->isOlderThan(500))->toBeFalse()
        ->and(Cursor::at(1)->isOlderThan(0))->toBeFalse();
});

it('cannot point before the start', function (): void {
    expect(static fn () => Cursor::at(-1))->toThrow(LogicException::class);
});
