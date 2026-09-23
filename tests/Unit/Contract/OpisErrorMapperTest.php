<?php

declare(strict_types=1);

use Opis\JsonSchema\CompliantValidator;
use TamasLabs\LaravelExpenses\Contract\ContractIssue;
use TamasLabs\LaravelExpenses\Contract\OpisErrorMapper;
use TamasLabs\LaravelExpenses\Support\Json;

/**
 * Validates JSON text against an inline schema and maps the errors.
 *
 * @return list<array{path: string, keyword: string, message: string}>
 */
function mapOpisErrors(string $schema, string $data, int $limit = 100): array
{
    $decodedSchema = Json::decode($schema);
    assert(is_object($decodedSchema));

    $error = (new CompliantValidator(null, 100, false))->validate(Json::decode($data), $decodedSchema)->error();

    if ($error === null) {
        return [];
    }

    return array_map(
        static fn (ContractIssue $issue): array => ['path' => $issue->path, 'keyword' => $issue->keyword, 'message' => $issue->message],
        (new OpisErrorMapper)->map($error, $limit),
    );
}

it('reports each missing property as its own required issue on the parent', function (): void {
    expect(mapOpisErrors('{"type": "object", "required": ["name", "color"]}', '{}'))->toBe([
        ['path' => '/', 'keyword' => 'required', 'message' => "must have required property 'name'"],
        ['path' => '/', 'keyword' => 'required', 'message' => "must have required property 'color'"],
    ]);
});

it('reports each undeclared property as its own additionalProperties issue on the parent', function (): void {
    $schema = '{"type": "object", "properties": {"a": {"type": "object", "properties": {}, "additionalProperties": false}}, "additionalProperties": false}';

    expect(mapOpisErrors($schema, '{"a": {"y": 1}, "x": 1, "z": 2}'))->toBe([
        ['path' => '/a', 'keyword' => 'additionalProperties', 'message' => 'must NOT have additional properties'],
        ['path' => '/', 'keyword' => 'additionalProperties', 'message' => 'must NOT have additional properties'],
        ['path' => '/', 'keyword' => 'additionalProperties', 'message' => 'must NOT have additional properties'],
    ]);
});

it('does not report declared properties as additional when one of them fails', function (): void {
    $schema = '{"type": "object", "properties": {"a": {"type": "string"}, "b": {"type": "string"}}, "additionalProperties": false}';

    expect(mapOpisErrors($schema, '{"a": 1, "b": "ok"}'))->toBe([
        ['path' => '/a', 'keyword' => 'type', 'message' => 'The data (integer) must match the type: string'],
    ]);
});

it('reports only the failed keywords, not the properties, items and $ref wrappers', function (): void {
    $schema = '{"$defs": {"qty": {"type": "number", "exclusiveMinimum": 0}}, "type": "object", "properties": {"items": {"type": "array", "items": {"type": "object", "properties": {"quantity": {"$ref": "#/$defs/qty"}}}}}}';

    expect(array_column(mapOpisErrors($schema, '{"items": [{"quantity": 1}, {"quantity": 0}]}'), 'keyword', 'path'))
        ->toBe(['/items/1/quantity' => 'exclusiveMinimum']);
});

it('reports the root as / and escapes pointer tokens per RFC 6901', function (): void {
    expect(mapOpisErrors('{"type": "string"}', '1'))->toBe([
        ['path' => '/', 'keyword' => 'type', 'message' => 'The data (integer) must match the type: string'],
    ])->and(array_column(mapOpisErrors('{"properties": {"a/b~c": {"type": "string"}}}', '{"a/b~c": 1}'), 'path'))
        ->toBe(['/a~1b~0c']);
});

it('reports the branches of anyOf and oneOf, then the combinator itself, as Ajv does', function (string $combinator): void {
    expect(array_column(mapOpisErrors("{\"{$combinator}\": [{\"type\": \"string\"}, {\"type\": \"integer\"}]}", 'true'), 'keyword'))
        ->toBe(['type', 'type', $combinator]);
})->with(['anyOf', 'oneOf']);

it('stops at the limit', function (): void {
    expect(mapOpisErrors('{"type": "object", "required": ["a", "b", "c"], "properties": {"d": {"type": "string"}}}', '{"d": 1}', 3))
        ->toHaveCount(3);
});
