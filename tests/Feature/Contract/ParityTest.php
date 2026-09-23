<?php

declare(strict_types=1);

use TamasLabs\ExpensesSchema\ExpensesSchema;
use TamasLabs\LaravelExpenses\Contract\ContractIssue;
use TamasLabs\LaravelExpenses\Contract\ContractValidator;
use TamasLabs\LaravelExpenses\Support\Json;
use TamasLabs\LaravelExpenses\Tests\TestCase;

/**
 * The shared validation cases of `expenses-schema/fixtures`, which the TS side
 * (Ajv) runs as well.
 *
 * @return array<string, array{object}>
 */
function schemaFixtures(): array
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        ExpensesSchema::fixturesDirectory(),
        FilesystemIterator::SKIP_DOTS,
    ));
    $cases = [];

    foreach ($files as $file) {
        if ($file instanceof SplFileInfo && preg_match('#/(valid|invalid)/[^/]+\.json$#', $file->getPathname()) === 1) {
            $case = Json::decode((string) file_get_contents($file->getPathname()));
            assert($case instanceof stdClass);
            $cases[substr($file->getPathname(), \strlen(ExpensesSchema::fixturesDirectory()) + 1)] = [$case];
        }
    }

    ksort($cases);

    return $cases;
}

/**
 * @return list<string>
 */
function pathKeywordPairs(mixed $issues): array
{
    $pairs = [];

    foreach (is_array($issues) ? $issues : [] as $issue) {
        if ($issue instanceof ContractIssue) {
            $pairs[] = $issue->path.' '.$issue->keyword;
        } elseif ($issue instanceof stdClass && is_string($issue->path ?? null) && is_string($issue->keyword ?? null)) {
            $pairs[] = $issue->path.' '.$issue->keyword;
        }
    }

    return $pairs;
}

it('decides every shared fixture like the TS side and reports the expected issues', function (stdClass $case): void {
    expect($case->target)->toBeString();
    assert(is_string($case->target));

    $result = app(ContractValidator::class)->validateAgainst(TestCase::contractSchemaId($case->target), $case->data);

    expect($result->valid)->toBe($case->valid);

    // Multiset containment: every expected path–keyword pair must be matched
    // by its own reported issue; extra reported issues are fine.
    $reported = pathKeywordPairs($result->issues);

    foreach (pathKeywordPairs($case->issues) as $expected) {
        $index = array_search($expected, $reported, true);

        expect($index)->not->toBeFalse("Expected issue \"{$expected}\" was not reported; got: ".implode(', ', pathKeywordPairs($result->issues)));

        unset($reported[$index]);
    }
})->with(schemaFixtures());

it('accepts a float amount with no fractional part in the raw JSON, as Ajv does', function (): void {
    $expense = ExpensesSchema::example('expense');
    $expense['amountMinor'] = 1.0;
    $json = json_encode($expense, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

    expect($json)->toContain('"amountMinor":1.0')
        ->and(app(ContractValidator::class)->validateJson('expense', $json)->valid)->toBeTrue();
});

it('leaves the existence of a referenced currency to the domain rules', function (): void {
    $expense = ExpensesSchema::example('expense');
    $expense['currencyId'] = '00000000-0000-4000-8000-000000000000';

    expect(app(ContractValidator::class)->validatePayload('expense', $expense)->valid)->toBeTrue();
});

it('reports malformed JSON as one json issue on the root', function (): void {
    $result = app(ContractValidator::class)->validateJson('expense', '{"name": ');

    expect($result->valid)->toBeFalse()
        ->and($result->issues)->toHaveCount(1)
        ->and($result->issues[0]->path)->toBe('/')
        ->and($result->issues[0]->keyword)->toBe('json');
});

it('keeps reporting past the first error, up to the issue limit', function (): void {
    $fixture = Json::decode((string) file_get_contents(ExpensesSchema::fixturesDirectory().'/resources/expense/invalid/zero-item-quantity.json'));
    assert($fixture instanceof stdClass && $fixture->data instanceof stdClass && is_array($fixture->data->items));
    $fixture->data->items = array_fill(0, 150, $fixture->data->items[0]);

    $result = app(ContractValidator::class)->validate('expense', $fixture->data);

    expect($result->issues)->toHaveCount(ContractValidator::MAX_ISSUES)
        ->and($result->issues[0]->path)->toBe('/items/0/quantity')
        ->and($result->issues[99]->path)->toBe('/items/99/quantity');
});

it('treats an unknown resource or schema id as a programming error', function (Closure $validate): void {
    $validate(app(ContractValidator::class));
})->throws(InvalidArgumentException::class)->with([
    'resource' => static fn (ContractValidator $validator) => $validator->validate('invoice', new stdClass),
    'foreign id' => static fn (ContractValidator $validator) => $validator->validateAgainst('https://example.com/invoice.schema.json', new stdClass),
    'missing document' => static fn (ContractValidator $validator) => $validator->validateAgainst(ExpensesSchema::BASE_URI.'invoice.schema.json', new stdClass),
]);
