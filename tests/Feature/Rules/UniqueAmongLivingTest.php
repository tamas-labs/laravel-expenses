<?php

declare(strict_types=1);

use TamasLabs\LaravelExpenses\Rules\RecordFields;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;
use TamasLabs\LaravelExpenses\Tests\Support\Records;
use TamasLabs\LaravelExpenses\Tests\Support\RuleRunner;

// Spec 05, 4.3 — S2: unique among the user's live records, byte for byte.

/**
 * @return array{path: string, keyword: string, message: string, params: array{conflictingId: string}}
 */
function uniqueIssue(string $resource, string $field, string $conflictingId): array
{
    return [
        'path' => "/{$field}",
        'keyword' => 'unique',
        'message' => "Another live {$resource} has the same {$field}.",
        'params' => ['conflictingId' => $conflictingId],
    ];
}

/**
 * The unique keys, as resource and field.
 *
 * @return array<string, array{string, string}>
 */
function uniqueKeys(): array
{
    return [
        'category name' => ['category', 'name'],
        'payment method name' => ['payment-method', 'name'],
        'subcategory name' => ['subcategory', 'name'],
        'product normalizedName' => ['product', 'normalizedName'],
        'product barcode' => ['product', 'barcode'],
    ];
}

/**
 * The fields a record of the user needs to take the key's shared value: the
 * value itself, and a subcategory's category.
 *
 * @return array<string, mixed>
 */
function sharedKey(string $resource, string $field, User $user): array
{
    $parent = $resource === 'subcategory' ? ['categoryId' => Records::stored('category', $user)->id] : [];

    return [...$parent, $field => '5998200123456'];
}

it('rejects a record taking the key of a live one, naming it', function (string $resource, string $field): void {
    $user = User::factory()->createOne();
    $shared = sharedKey($resource, $field, $user);
    $live = Records::stored($resource, $user, $shared);

    expect(RuleRunner::check($user, $resource, Records::make($resource, $shared)))
        ->toBe([[uniqueIssue($resource, $field, RecordFields::id($live))]]);
})->with(uniqueKeys());

it('lets a record take the key of a tombstone', function (string $resource, string $field): void {
    $user = User::factory()->createOne();
    $shared = sharedKey($resource, $field, $user);
    Records::stored($resource, $user, [...$shared, 'deletedAt' => '2026-09-14T08:30:00.000Z']);

    expect(RuleRunner::check($user, $resource, Records::make($resource, $shared)))->toBe([[]]);
})->with(uniqueKeys());

it('does not check a tombstone', function (string $resource, string $field): void {
    $user = User::factory()->createOne();
    $shared = sharedKey($resource, $field, $user);
    Records::stored($resource, $user, $shared);

    expect(RuleRunner::check($user, $resource, Records::make($resource, [...$shared, 'deletedAt' => '2026-09-14T08:30:00.000Z'])))
        ->toBe([[]]);
})->with(uniqueKeys());

it('lets a record keep its own key', function (string $resource, string $field): void {
    $user = User::factory()->createOne();
    $record = Records::stored($resource, $user, sharedKey($resource, $field, $user));

    expect(RuleRunner::check($user, $resource, Records::changed($record, [])))->toBe([[]]);
})->with(uniqueKeys());

it('rejects the second of two new records with the same key in one push, naming the first', function (string $resource, string $field): void {
    $user = User::factory()->createOne();
    $shared = sharedKey($resource, $field, $user);
    $first = Records::make($resource, $shared);

    expect(RuleRunner::check($user, $resource, $first, Records::make($resource, $shared)))
        ->toBe([[], [uniqueIssue($resource, $field, RecordFields::id($first))]]);
})->with(uniqueKeys());

it('does not compare with another user\'s records', function (string $resource, string $field): void {
    $alice = User::factory()->createOne();
    $bob = User::factory()->createOne();
    Records::stored($resource, $alice, sharedKey($resource, $field, $alice));

    expect(RuleRunner::check($bob, $resource, Records::make($resource, sharedKey($resource, $field, $bob))))->toBe([[]]);
})->with(uniqueKeys());

it('lets a subcategory name repeat in another category', function (): void {
    $user = User::factory()->createOne();
    $food = Records::stored('category', $user);
    $home = Records::stored('category', $user);
    Records::stored('subcategory', $user, ['categoryId' => $food->id, 'name' => 'Egyéb']);

    expect(RuleRunner::check($user, 'subcategory', Records::make('subcategory', ['categoryId' => $home->id, 'name' => 'Egyéb'])))->toBe([[]]);
});

it('compares names byte for byte', function (string $other): void {
    $user = User::factory()->createOne();
    Records::stored('category', $user, ['name' => 'Élelmiszer']);

    expect(RuleRunner::check($user, 'category', Records::make('category', ['name' => $other])))->toBe([[]]);
})->with([
    'case' => 'élelmiszer',
    'accent' => 'Elelmiszer',
    'trailing space' => 'Élelmiszer ',
    'decomposed accent (NFD)' => "E\u{0301}lelmiszer",
]);

it('lets any number of products go without a barcode', function (): void {
    $user = User::factory()->createOne();
    Records::stored('product', $user);

    expect(RuleRunner::check($user, 'product', Records::make('product'), Records::make('product')))->toBe([[], []]);
});

it('judges the state the push leaves: two records may swap their names', function (): void {
    $user = User::factory()->createOne();
    $a = Records::stored('category', $user, ['name' => 'X']);
    $b = Records::stored('category', $user, ['name' => 'Y']);

    expect(RuleRunner::check($user, 'category', Records::changed($a, ['name' => 'Y']), Records::changed($b, ['name' => 'X'])))
        ->toBe([[], []]);
});

it('lets a record take a key the same push frees with a tombstone', function (): void {
    $user = User::factory()->createOne();
    $old = Records::stored('category', $user, ['name' => 'Élelmiszer']);

    expect(RuleRunner::check($user, 'category', Records::make('category', ['name' => 'Élelmiszer']), Records::deleted($old)))
        ->toBe([[], []]);
});

it('keeps the key of a record the push rejects', function (): void {
    // B cannot move to W, so it keeps Y on the server, and A cannot have Y.
    $user = User::factory()->createOne();
    $a = Records::stored('category', $user, ['name' => 'X']);
    $b = Records::stored('category', $user, ['name' => 'Y']);
    $w = Records::stored('category', $user, ['name' => 'W']);

    expect(RuleRunner::check($user, 'category', Records::changed($a, ['name' => 'Y']), Records::changed($b, ['name' => 'W'])))
        ->toBe([[uniqueIssue('category', 'name', RecordFields::id($b))], [uniqueIssue('category', 'name', RecordFields::id($w))]]);
});
