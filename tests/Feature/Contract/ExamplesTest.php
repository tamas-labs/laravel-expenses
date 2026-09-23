<?php

declare(strict_types=1);

use TamasLabs\ExpensesSchema\ExpensesSchema;
use TamasLabs\LaravelExpenses\Contract\ContractValidator;
use TamasLabs\LaravelExpenses\Support\Json;
use TamasLabs\LaravelExpenses\Tests\TestCase;

/**
 * Every `examples` entry of every resource and protocol document, once decoded
 * with objects (for `validate()`) and once as PHP arrays (for `validatePayload()`).
 *
 * @return array<string, array{string, mixed, array<array-key, mixed>}>
 */
function contractExamples(): array
{
    $targets = [
        ...ExpensesSchema::resources(),
        ...array_map(static fn (string $name): string => 'protocol/'.$name, ExpensesSchema::protocol()),
    ];
    $cases = [];

    foreach ($targets as $target) {
        $asObjects = Json::decode((string) file_get_contents(ExpensesSchema::path($target)));
        $asArrays = ExpensesSchema::get($target)['examples'] ?? [];

        foreach (is_object($asObjects) && isset($asObjects->examples) && is_array($asObjects->examples) ? $asObjects->examples : [] as $index => $example) {
            /** @var array<array-key, mixed> $array */
            $array = is_array($asArrays) && is_array($asArrays[$index] ?? null) ? $asArrays[$index] : [];
            $cases["{$target} #{$index}"] = [$target, $example, $array];
        }
    }

    return $cases;
}

it('ships at least one example for every resource', function (string $resource): void {
    expect(ExpensesSchema::get($resource)['examples'] ?? null)->toBeArray()
        ->and(count((array) (ExpensesSchema::get($resource)['examples'] ?? [])))->toBeGreaterThan(0);
})->with(ExpensesSchema::resources());

it('accepts every example through validate() and validatePayload()', function (string $target, mixed $decoded, array $payload): void {
    $validator = app(ContractValidator::class);
    $schemaId = TestCase::contractSchemaId($target);

    expect($validator->validateAgainst($schemaId, $decoded)->issues)->toBe([])
        ->and($validator->validatePayloadAgainst($schemaId, $payload)->issues)->toBe([]);
})->with(contractExamples());
