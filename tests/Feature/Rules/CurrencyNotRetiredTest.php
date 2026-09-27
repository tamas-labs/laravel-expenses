<?php

declare(strict_types=1);

use TamasLabs\LaravelExpenses\Database\Currencies;
use TamasLabs\LaravelExpenses\Models\Currency;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;
use TamasLabs\LaravelExpenses\Tests\Support\Records;
use TamasLabs\LaravelExpenses\Tests\Support\RuleRunner;

// Spec 05, 4.4 — S3: a retired currency cannot be chosen anew.

const RETIRED_ISSUE = [
    'path' => '/currencyId',
    'keyword' => 'retired',
    'message' => 'The currency is retired: only a record that already had it may keep it.',
    'params' => ['id' => Currencies::USD],
];

function retireUsd(): void
{
    Currency::query()->whereKey(Currencies::USD)->update(['deleted_at' => '2026-09-01 00:00:00.000']);
}

/**
 * A record of the resource the user can push, apart from its currency.
 *
 * @return array<string, Closure(User): array<string, mixed>>
 */
function currencyReferrers(): array
{
    return [
        'expense' => static fn (User $user): array => [],
        'product-price' => static fn (User $user): array => ['productId' => Records::stored('product', $user)->id],
    ];
}

it('rejects a new record with a retired currency', function (string $resource): void {
    $user = User::factory()->createOne();
    $fields = currencyReferrers()[$resource]($user);
    retireUsd();

    expect(RuleRunner::check($user, $resource, Records::make($resource, [...$fields, 'currencyId' => Currencies::USD])))
        ->toBe([[RETIRED_ISSUE]]);
})->with(array_keys(currencyReferrers()));

it('lets a stored record keep its retired currency', function (string $resource): void {
    $user = User::factory()->createOne();
    $record = Records::stored($resource, $user, [...currencyReferrers()[$resource]($user), 'currencyId' => Currencies::USD]);
    retireUsd();

    expect(RuleRunner::check($user, $resource, Records::changed($record, [])))
        ->toBe([[]]);
})->with(array_keys(currencyReferrers()));

it('rejects a stored record switching to a retired currency', function (string $resource): void {
    $user = User::factory()->createOne();
    $record = Records::stored($resource, $user, [...currencyReferrers()[$resource]($user), 'currencyId' => Currencies::HUF]);
    retireUsd();

    expect(RuleRunner::check($user, $resource, Records::changed($record, ['currencyId' => Currencies::USD])))
        ->toBe([[RETIRED_ISSUE]]);
})->with(array_keys(currencyReferrers()));

it('does not check a tombstone', function (): void {
    $user = User::factory()->createOne();
    retireUsd();

    expect(RuleRunner::check($user, 'expense', Records::make('expense', ['currencyId' => Currencies::USD, 'deletedAt' => '2026-09-14T08:30:00.000Z'])))
        ->toBe([[]]);
});

it('lets a record choose a currency in use', function (): void {
    $user = User::factory()->createOne();
    retireUsd();

    expect(RuleRunner::check($user, 'expense', Records::make('expense', ['currencyId' => Currencies::EUR])))->toBe([[]]);
});
