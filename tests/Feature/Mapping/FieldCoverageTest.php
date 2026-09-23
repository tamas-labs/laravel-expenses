<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use TamasLabs\ExpensesSchema\ExpensesSchema;
use TamasLabs\LaravelExpenses\Mapping\Field;
use TamasLabs\LaravelExpenses\Mapping\FieldType;
use TamasLabs\LaravelExpenses\Registry\ResourceRegistry;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Tests\Support\ContractSchema;

// Spec 04, 4.2: a mapper's field list, the schema and the table cannot drift apart.

it('lists exactly the schema\'s properties, in its order', function (string $resource): void {
    $names = array_map(static fn (Field $field): string => $field->name, app(ResourceRegistry::class)->mapper($resource)->describe());

    expect($names)->toBe(ContractSchema::propertyNames($resource));
})->with(ExpensesSchema::resources());

it('maps every field to a column of the model\'s table', function (string $resource): void {
    $registry = app(ResourceRegistry::class);
    $table = (new ($registry->get($resource)->model))->getTable();
    $columns = Schema::getColumnListing($table);

    foreach ($registry->mapper($resource)->describe() as $field) {
        if ($field->type === FieldType::Links) {
            expect(Schema::hasTable(PackageConfig::table($field->column)))->toBeTrue("{$field->name}: no {$field->column} table");
        } else {
            expect($columns)->toContain($field->column);
        }
    }
})->with(ExpensesSchema::resources());

it('makes a field nullable exactly where the schema allows null', function (string $resource): void {
    foreach (app(ResourceRegistry::class)->mapper($resource)->describe() as $field) {
        expect($field->nullable)->toBe(ContractSchema::allowsNull($resource, $field->name), "{$resource}.{$field->name}");
    }
})->with(ExpensesSchema::resources());
