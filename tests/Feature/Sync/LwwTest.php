<?php

declare(strict_types=1);

use TamasLabs\LaravelExpenses\Models\BudgetPocket;
use TamasLabs\LaravelExpenses\Models\Category;
use TamasLabs\LaravelExpenses\Models\SyncModel;
use TamasLabs\LaravelExpenses\Registry\ResourceRegistry;
use TamasLabs\LaravelExpenses\Sync\Lww;
use TamasLabs\LaravelExpenses\Sync\LwwOutcome;
use TamasLabs\LaravelExpenses\Tests\Support\Records;

// Spec 06, 5.1 and 5.2: the rule alone, on unsaved models.

/**
 * A record as the push sees it: mapped onto an unsaved model.
 */
function lwwModel(string $resource, stdClass $record): SyncModel
{
    $mapped = app(ResourceRegistry::class)->mapper($resource)->toAttributes($record);
    $model = new (app(ResourceRegistry::class)->ownedModel($resource));
    $model->forceFill($mapped->attributes);

    if ($model instanceof BudgetPocket) {
        $model->categoryIds = $mapped->links['categoryIds'] ?? [];
    }

    return $model;
}

function lww(stdClass $pushed, ?stdClass $server, string $resource = 'category'): LwwOutcome
{
    return Lww::decide(
        lwwModel($resource, $pushed),
        $server === null ? null : lwwModel($resource, $server),
        app(ResourceRegistry::class)->mapper($resource),
    );
}

it('lets a new record win', function (): void {
    expect(lww(Records::make('category'), null))->toBe(LwwOutcome::PushedWins);
});

it('lets the later updatedAt win, to the millisecond', function (): void {
    $server = Records::make('category', ['updatedAt' => '2026-09-01T10:00:00.500Z']);

    expect(lww(Records::changed($server, ['updatedAt' => '2026-09-01T10:00:00.501Z']), $server))->toBe(LwwOutcome::PushedWins)
        ->and(lww(Records::changed($server, ['updatedAt' => '2026-09-01T10:00:00.499Z']), $server))->toBe(LwwOutcome::ServerWins);
});

it('compares instants, not strings', function (): void {
    $server = Records::make('category', ['updatedAt' => '2026-09-01T10:00:00.000Z']);

    // Later as a string, earlier as an instant.
    expect(lww(Records::changed($server, ['updatedAt' => '2026-09-01T11:30:00.000+02:00']), $server))->toBe(LwwOutcome::ServerWins)
        ->and(lww(Records::changed($server, ['updatedAt' => '2026-09-01T09:30:00.000-01:00']), $server))->toBe(LwwOutcome::PushedWins)
        ->and(lww(Records::changed($server, ['updatedAt' => '2026-09-01T12:00:00+02:00']), $server))->toBe(LwwOutcome::Unchanged);
});

it('ignores digits past the millisecond, which the server does not keep', function (): void {
    $server = Records::make('category', ['updatedAt' => '2026-09-01T10:00:00.123Z']);

    expect(lww(Records::changed($server, ['updatedAt' => '2026-09-01T10:00:00.123999Z']), $server))->toBe(LwwOutcome::Unchanged);
});

it('changes nothing on a tie with the same content, and keeps the server version on a tie with other content', function (): void {
    $server = Records::make('category');

    expect(lww(clone $server, $server))->toBe(LwwOutcome::Unchanged)
        ->and(lww(Records::changed($server, ['name' => 'Other', 'updatedAt' => $server->updatedAt]), $server))->toBe(LwwOutcome::ServerWins)
        ->and(lww(Records::changed($server, ['sortOrder' => null, 'updatedAt' => $server->updatedAt]), $server))->toBe(LwwOutcome::ServerWins);
});

it('treats a deletion as a change competing with its own updatedAt', function (): void {
    $live = Records::make('category', ['updatedAt' => '2026-09-01T10:00:00.000Z']);
    $tombstone = Records::changed($live, ['deletedAt' => '2026-09-02T10:00:00.000Z', 'updatedAt' => '2026-09-02T10:00:00.000Z']);

    expect(lww($tombstone, $live))->toBe(LwwOutcome::PushedWins)
        ->and(lww($live, $tombstone))->toBe(LwwOutcome::ServerWins)
        ->and(lww(Records::changed($tombstone, ['deletedAt' => null, 'updatedAt' => '2026-09-03T10:00:00.000Z']), $tombstone))->toBe(LwwOutcome::PushedWins)
        ->and(lww(Records::changed($tombstone, ['deletedAt' => null, 'updatedAt' => $tombstone->updatedAt]), $tombstone))->toBe(LwwOutcome::ServerWins);
});

it('takes a pocket\'s categories as a set', function (): void {
    $ids = ['00000000-0000-4000-8000-00000000000a', '00000000-0000-4000-8000-00000000000b'];
    $server = Records::make('budget-pocket', ['categoryIds' => $ids]);

    expect(lww(Records::changed($server, ['categoryIds' => array_reverse($ids), 'updatedAt' => $server->updatedAt]), $server, 'budget-pocket'))->toBe(LwwOutcome::Unchanged)
        ->and(lww(Records::changed($server, ['categoryIds' => [$ids[0]], 'updatedAt' => $server->updatedAt]), $server, 'budget-pocket'))->toBe(LwwOutcome::ServerWins);
});

it('compares JSON fields byte for byte', function (): void {
    $server = Records::make('product', ['customFields' => (object) ['a' => 1, 'b' => 2]]);

    expect(lww(Records::changed($server, ['customFields' => (object) ['b' => 2, 'a' => 1], 'updatedAt' => $server->updatedAt]), $server, 'product'))->toBe(LwwOutcome::ServerWins)
        ->and(lww(Records::changed($server, ['customFields' => (object) ['a' => 1, 'b' => 2], 'updatedAt' => $server->updatedAt]), $server, 'product'))->toBe(LwwOutcome::Unchanged);
});

it('reads updatedAt through the mapper, whatever the column', function (): void {
    $category = new Category;
    $category->forceFill(['updated_at' => null]);

    expect(static fn () => Lww::decide($category, $category, app(ResourceRegistry::class)->mapper('category')))
        ->toThrow(LogicException::class, 'holds no date');
});
