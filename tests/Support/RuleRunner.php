<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Tests\Support;

use stdClass;
use TamasLabs\LaravelExpenses\Contract\ContractIssue;
use TamasLabs\LaravelExpenses\Registry\ResourceRegistry;
use TamasLabs\LaravelExpenses\Rules\DomainValidator;
use TamasLabs\LaravelExpenses\Rules\RecordFields;
use TamasLabs\LaravelExpenses\Rules\RuleContext;
use TamasLabs\LaravelExpenses\Sync\SyncStore;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;
use TamasLabs\LaravelExpenses\Tests\TestCase;

/**
 * Runs the domain rules over a push the way the sync will (spec 06, 5.3):
 * resource by resource in the push order, every record checked against the
 * contract first, the server's rows loaded into the context, and a record
 * accepted when no rule objects (there is no last-write-wins step here).
 */
final class RuleRunner
{
    /**
     * @param  array<string, list<stdClass>>  $push  Resource → its records.
     * @return array<string, array<int, list<array{path: string, keyword: string, message: string, params?: array<string, string|int|float|bool|null>}>>>
     */
    public static function push(User $user, array $push, ?RuleContext $context = null): array
    {
        $context ??= new RuleContext($user, app(SyncStore::class));
        $results = [];

        foreach (app(ResourceRegistry::class)->pushable() as $definition) {
            $records = $push[$definition->name] ?? null;

            if ($records === null) {
                continue;
            }

            foreach ($records as $record) {
                TestCase::assertMatchesContract($definition->name, $record);
            }

            self::loadExisting($context, $definition->name, $records);
            $issues = app(DomainValidator::class)->validate($definition->name, $records, $context);

            foreach ($records as $index => $record) {
                if ($issues[$index] === []) {
                    $context->accept($definition->name, $record);
                } else {
                    $context->reject($definition->name, RecordFields::id($record));
                }
            }

            $results[$definition->name] = array_map(
                static fn (array $found): array => array_map(static fn (ContractIssue $issue): array => $issue->toArray(), $found),
                $issues,
            );
        }

        return $results;
    }

    /**
     * The issues of one resource's records, pushed alone.
     *
     * @return array<int, list<array{path: string, keyword: string, message: string, params?: array<string, string|int|float|bool|null>}>>
     */
    public static function check(User $user, string $resource, stdClass ...$records): array
    {
        return self::push($user, [$resource => array_values($records)])[$resource];
    }

    /**
     * Sets the server's version of the records in the context, as the sync
     * loads it for last-write-wins.
     *
     * @param  list<stdClass>  $records
     */
    public static function loadExisting(RuleContext $context, string $resource, array $records): void
    {
        $context->setExisting($resource, $context->store->existing($resource, $context->user, array_map(RecordFields::id(...), $records)));
    }
}
