<?php

declare(strict_types=1);

use TamasLabs\ExpensesSchema\ExpensesSchema;
use TamasLabs\LaravelExpenses\Contract\ContractValidator;
use TamasLabs\LaravelExpenses\Tests\TestCase;

it('validates the wire form, so an empty PHP array is not an empty object', function (): void {
    $product = [...ExpensesSchema::example('product'), 'customFields' => []];

    $result = app(ContractValidator::class)->validatePayload('product', $product);

    expect($result->valid)->toBeFalse()
        ->and(array_map(static fn ($issue): array => [$issue->path, $issue->keyword], $result->issues))->toContain(['/customFields', 'type']);
});

it('accepts an empty object where the contract wants one', function (): void {
    $product = [...ExpensesSchema::example('product'), 'customFields' => new stdClass];

    expect(app(ContractValidator::class)->validatePayload('product', $product)->valid)->toBeTrue();
});

it('encodes a JsonSerializable payload before validating it', function (): void {
    $payload = new class implements JsonSerializable
    {
        /** @return array<string, mixed> */
        public function jsonSerialize(): array
        {
            return ExpensesSchema::example('category');
        }
    };

    TestCase::assertMatchesContract('category', $payload);
});
