<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Tests\Support;

use RuntimeException;
use stdClass;
use TamasLabs\ExpensesSchema\ExpensesSchema;
use TamasLabs\LaravelExpenses\Support\Json;

/**
 * Reads the resource schemas for the mapping tests: property lists, whether a
 * property takes `null`, and the examples decoded the way records arrive.
 */
final class ContractSchema
{
    /**
     * The resource's properties, in the schema's order.
     *
     * @return list<string>
     */
    public static function propertyNames(string $resource): array
    {
        $properties = ExpensesSchema::get($resource)['properties'] ?? null;

        return \is_array($properties) ? array_map(strval(...), array_keys($properties)) : [];
    }

    /**
     * Whether the property accepts `null`, following `$ref`s into the other documents.
     */
    public static function allowsNull(string $resource, string $property): bool
    {
        $properties = ExpensesSchema::get($resource)['properties'] ?? null;
        $schema = \is_array($properties) ? ($properties[$property] ?? null) : null;

        if (! \is_array($schema)) {
            throw new RuntimeException(sprintf('The %s schema has no "%s" property.', $resource, $property));
        }

        return self::acceptsNull($schema, $resource);
    }

    /**
     * How many examples the resource's schema ships.
     */
    public static function exampleCount(string $resource): int
    {
        $examples = ExpensesSchema::get($resource)['examples'] ?? [];

        return \is_array($examples) ? \count($examples) : 0;
    }

    /**
     * One example, decoded with objects as a pushed record is. A fresh copy on
     * every call, so a test may change it.
     */
    public static function example(string $resource, int $index = 0): stdClass
    {
        $document = Json::decode((string) file_get_contents(ExpensesSchema::path($resource)));
        $example = $document instanceof stdClass && \is_array($document->examples ?? null) ? ($document->examples[$index] ?? null) : null;

        if (! $example instanceof stdClass) {
            throw new RuntimeException(sprintf('The %s schema has no example #%d.', $resource, $index));
        }

        return $example;
    }

    /**
     * Every keyword that can rule out `null` has to let it through (they all
     * apply at once, `$ref` included).
     *
     * @param  array<array-key, mixed>  $schema
     */
    private static function acceptsNull(array $schema, string $document): bool
    {
        $type = $schema['type'] ?? null;

        if ($type !== null && ! ($type === 'null' || (\is_array($type) && \in_array('null', $type, true)))) {
            return false;
        }

        if (\array_key_exists('const', $schema) && $schema['const'] !== null) {
            return false;
        }

        if (isset($schema['enum']) && ! (\is_array($schema['enum']) && \in_array(null, $schema['enum'], true))) {
            return false;
        }

        foreach (['anyOf', 'oneOf'] as $keyword) {
            if (isset($schema[$keyword]) && \is_array($schema[$keyword])
                && array_filter($schema[$keyword], static fn (mixed $branch): bool => \is_array($branch) && self::acceptsNull($branch, $document)) === []) {
                return false;
            }
        }

        if (isset($schema['allOf']) && \is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $branch) {
                if (\is_array($branch) && ! self::acceptsNull($branch, $document)) {
                    return false;
                }
            }
        }

        if (isset($schema['$ref']) && \is_string($schema['$ref'])) {
            [$target, $referenced] = self::resolve($schema['$ref'], $document);

            return self::acceptsNull($referenced, $target);
        }

        return true;
    }

    /**
     * A `$ref` of the contract: `#/$defs/x` or `common.schema.json#/$defs/x`.
     *
     * @return array{string, array<array-key, mixed>}
     */
    private static function resolve(string $ref, string $document): array
    {
        [$file, $pointer] = explode('#', $ref, 2) + [1 => ''];
        $target = $file === '' ? $document : $file;
        $node = ExpensesSchema::get($target);

        foreach (array_filter(explode('/', $pointer), static fn (string $part): bool => $part !== '') as $part) {
            $node = \is_array($node) ? ($node[str_replace(['~1', '~0'], ['/', '~'], $part)] ?? null) : null;
        }

        if (! \is_array($node)) {
            throw new RuntimeException(sprintf('Cannot resolve "%s" from %s.', $ref, $document));
        }

        return [$target, $node];
    }
}
