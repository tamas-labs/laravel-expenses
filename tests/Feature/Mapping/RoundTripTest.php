<?php

declare(strict_types=1);

use TamasLabs\ExpensesSchema\ExpensesSchema;
use TamasLabs\LaravelExpenses\Contract\ContractValidator;
use TamasLabs\LaravelExpenses\Models\Currency;
use TamasLabs\LaravelExpenses\Registry\ResourceRegistry;
use TamasLabs\LaravelExpenses\Support\Json;
use TamasLabs\LaravelExpenses\Tests\Fixtures\UserFactory;
use TamasLabs\LaravelExpenses\Tests\Support\ContractSchema;
use TamasLabs\LaravelExpenses\Tests\Support\RecordStore;
use TamasLabs\LaravelExpenses\Tests\TestCase;

// Spec 04, 4.1: a record the client sends is stored, read back with a fresh
// query and sent again as the same JSON — values, types and key order.

/**
 * Every example of every resource a client can send (the currencies are
 * seeded by the server, see below).
 *
 * @return array<string, array{string, int}>
 */
function roundTripExamples(): array
{
    $cases = [];

    foreach (array_diff(ExpensesSchema::resources(), ['currency']) as $resource) {
        for ($index = 0; $index < ContractSchema::exampleCount($resource); $index++) {
            $cases["{$resource} #{$index}"] = [$resource, $index];
        }
    }

    return $cases;
}

/**
 * Stores the record after its parents (the other examples) and asserts it
 * comes back byte for byte; returns what came back.
 */
function assertRoundTrip(string $resource, stdClass $record): string
{
    $user = UserFactory::new()->createOne();

    expect(app(ContractValidator::class)->validate($resource, $record)->issues)->toBe([]);

    RecordStore::storeParents($resource, $user);
    $expected = Json::encode(roundTripSortedLinks($record));
    $json = RecordStore::roundTrip($resource, $record, $user);

    expect($json)->toBe($expected);
    TestCase::assertMatchesContract($resource, $json);

    return $json;
}

/**
 * A pocket's categoryIds are a set; the mapper sends them sorted (spec 04, 3.6).
 */
function roundTripSortedLinks(stdClass $record): stdClass
{
    if (isset($record->categoryIds) && is_array($record->categoryIds)) {
        $ids = array_filter($record->categoryIds, is_string(...));
        sort($ids, SORT_STRING);
        $record->categoryIds = $ids;
    }

    return $record;
}

it('round-trips every example byte for byte', function (string $resource, int $index): void {
    assertRoundTrip($resource, ContractSchema::example($resource, $index));
})->with(roundTripExamples());

it('sends the seeded currencies exactly as the contract examples them', function (int $index): void {
    $example = ContractSchema::example('currency', $index);
    $currency = Currency::query()->whereKey($example->id)->sole();

    expect(RecordStore::encode('currency', $currency))->toBe(Json::encode($example));
})->with(fn (): array => range(0, ContractSchema::exampleCount('currency') - 1));

it('maps no incoming currency', function (): void {
    app(ResourceRegistry::class)->mapper('currency')->toAttributes(ContractSchema::example('currency'));
})->throws(LogicException::class);

it('keeps an empty object, an empty list and a null apart', function (): void {
    $product = ContractSchema::example('product');
    $product->customFields = new stdClass;
    $product->userTags = [];
    $product->offData = null;

    expect(assertRoundTrip('product', $product))->toContain('"offData":null', '"userTags":[]', '"customFields":{}');
});

it('keeps the key order of a JSON field', function (): void {
    $product = ContractSchema::example('product');
    $product->customFields = Json::decode('{"b":"1","a":"2"}');

    expect(assertRoundTrip('product', $product))->toContain('"customFields":{"b":"1","a":"2"}');
});

it('keeps a nested JSON tree unchanged', function (): void {
    $product = ContractSchema::example('product');
    $product->offData = Json::decode('{"nutriments":{"salt":0.1,"energyKj":268,"fat":2.8},"brand":"Mizo","allergens":["milk"],"novaGroup":1}');

    expect(assertRoundTrip('product', $product))
        ->toContain('"offData":{"nutriments":{"salt":0.1,"energyKj":268,"fat":2.8},"brand":"Mizo","allergens":["milk"],"novaGroup":1}');
});

it('keeps a quantity bit for bit', function (float $quantity): void {
    $item = ContractSchema::example('shopping-list-item');
    $item->quantity = $quantity;

    $sent = Json::decode(assertRoundTrip('shopping-list-item', $item));
    $sentQuantity = $sent instanceof stdClass ? $sent->quantity : null;

    expect(is_int($sentQuantity) || is_float($sentQuantity) ? (float) $sentQuantity : $sentQuantity)->toBe($quantity);
})->with([
    '0.1' => 0.1,
    'whole' => 1.0,
    '2.8' => 2.8,
    '1/3' => 1 / 3,
]);

it('sends a whole quantity as an integer', function (): void {
    $item = ContractSchema::example('shopping-list-item');
    $item->quantity = 1.0;

    expect(assertRoundTrip('shopping-list-item', $item))->toContain('"quantity":1,');
});

it('sends an amount that arrived as 1.0 as 1', function (): void {
    $expense = ContractSchema::example('expense');
    $expense->items = [];
    $expense->amountMinor = Json::decode('1.0');

    expect($expense->amountMinor)->toBe(1.0)
        ->and(assertRoundTrip('expense', $expense))->toContain('"amountMinor":1,');
});

it('keeps the largest integer JavaScript represents exactly', function (): void {
    $expense = ContractSchema::example('expense');
    $expense->items = [];
    $expense->amountMinor = 9007199254740991;

    expect(assertRoundTrip('expense', $expense))->toContain('"amountMinor":9007199254740991,');
});

it('sends Unicode text unescaped', function (): void {
    $category = ContractSchema::example('category');
    $category->name = 'Élelmiszer 🥦';

    $json = assertRoundTrip('category', $category);

    expect($json)->toContain('"name":"Élelmiszer 🥦"')
        ->and(str_contains($json, '\u'))->toBeFalse();
});

it('neither trims nor normalises text', function (): void {
    $category = ContractSchema::example('category');
    $category->name = "Cafe\u{0301} ";

    expect(assertRoundTrip('category', $category))->toContain("\"name\":\"Cafe\u{0301} \"");
});

it('round-trips a tombstone', function (): void {
    $category = ContractSchema::example('category');
    $category->updatedAt = '2026-09-15T10:00:00.000Z';
    $category->deletedAt = '2026-09-15T10:00:00.000Z';

    expect(assertRoundTrip('category', $category))->toContain('"deletedAt":"2026-09-15T10:00:00.000Z"');
});
