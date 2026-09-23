<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use TamasLabs\LaravelExpenses\Database\Factories\ProductFactory;
use TamasLabs\LaravelExpenses\Support\Json;
use TamasLabs\LaravelExpenses\Support\PackageConfig;

// Spec 04, 3.5: the JSON text columns hold the decoded tree, `{}` and `[]` apart.

it('stores and reads an empty object, an empty list and a null apart', function (): void {
    $product = ProductFactory::new()->createOne([
        'custom_fields' => new stdClass,
        'user_tags' => [],
        'off_data' => null,
    ]);

    $stored = DB::table(PackageConfig::table('products'))
        ->where('user_id', $product->user_id)
        ->where('id', $product->id)
        ->first(['custom_fields', 'user_tags', 'off_data']);
    $fresh = $product->fresh();

    expect((array) $stored)->toBe(['custom_fields' => '{}', 'user_tags' => '[]', 'off_data' => null])
        ->and($fresh?->custom_fields)->toEqual(new stdClass)
        ->and($fresh?->user_tags)->toBe([])
        ->and($fresh?->off_data)->toBeNull();
});

it('reads objects as stdClass at every level', function (): void {
    $product = ProductFactory::new()->createOne([
        'off_data' => Json::decode('{"brand":"Mizo","nutriments":{}}'),
    ])->fresh();

    expect($product?->off_data)->toBeInstanceOf(stdClass::class)
        ->and($product?->off_data?->nutriments)->toBeInstanceOf(stdClass::class);
});

it('refuses an associative array, which would lose an empty object', function (): void {
    ProductFactory::new()->makeOne(['custom_fields' => ['a' => '1']]);
})->throws(InvalidArgumentException::class, 'associative array');

it('refuses a string, which would be ambiguous', function (): void {
    ProductFactory::new()->makeOne(['custom_fields' => '{}']);
})->throws(InvalidArgumentException::class, 'string');

it('gives a fresh tree on every read', function (): void {
    $product = ProductFactory::new()->createOne(['custom_fields' => Json::decode('{"a":"1"}')]);

    $fields = $product->custom_fields;
    $fields->b = '2';

    expect($product->custom_fields)->toEqual(Json::decode('{"a":"1"}'))
        ->and($product->isDirty('custom_fields'))->toBeFalse();
});

it('writes a changed tree when it is set again', function (): void {
    $product = ProductFactory::new()->createOne(['custom_fields' => Json::decode('{"a":"1"}')]);

    $fields = $product->custom_fields;
    $fields->b = '2';
    $product->custom_fields = $fields;
    $product->save();

    expect($product->fresh()?->getRawOriginal('custom_fields'))->toBe('{"a":"1","b":"2"}');
});

it('relies on the shortest exact float form', function (): void {
    expect(ini_get('serialize_precision'))->toBe('-1')
        ->and(Json::encode([0.1, 2.8, 1 / 3, 3.0]))->toBe('[0.1,2.8,0.3333333333333333,3]');
});
