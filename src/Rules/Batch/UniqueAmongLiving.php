<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Rules\Batch;

use Illuminate\Database\Eloquent\Builder;
use TamasLabs\LaravelExpenses\Contract\ContractIssue;
use TamasLabs\LaravelExpenses\Models\SyncModel;
use TamasLabs\LaravelExpenses\Registry\ResourceRegistry;
use TamasLabs\LaravelExpenses\Rules\BatchRule;
use TamasLabs\LaravelExpenses\Rules\RecordFields;
use TamasLabs\LaravelExpenses\Rules\RuleContext;

/**
 * S2: the application's mirror of the unique indexes (spec 03, 3.6): among
 * the user's live records, byte for byte.
 *
 * The rule judges the state the push leaves behind. A pushed record replaces
 * the server's version of itself, so it never collides with itself, and two
 * records may swap their names in one push. A tombstone is not checked and
 * frees its key. Of two records claiming the same key, the later one fails.
 * A record that fails keeps its server version, which may then hold the key
 * another record of the push wanted: the check repeats until no new record
 * fails.
 *
 * The issue names the live record holding the key (`conflictingId`), which the
 * client merges its record into. It is the user's own record, so nothing leaks.
 */
final class UniqueAmongLiving implements BatchRule
{
    public const string KEYWORD = 'unique';

    /**
     * @param  string  $resource  The resource whose records are checked.
     * @param  array<string, list<string>>  $keys  Unique field → the fields it is unique
     *                                             within (`name` → `categoryId`).
     */
    public function __construct(
        private readonly ResourceRegistry $registry,
        public readonly string $resource,
        public readonly array $keys,
    ) {}

    public function check(array $records, RuleContext $context): array
    {
        $recordIds = array_map(RecordFields::id(...), $records);
        $recordKeys = array_map($this->recordKeys(...), $records);
        $acceptedKeys = array_map($this->recordKeys(...), $context->acceptedOf($this->resource));
        $rowKeys = $this->livingRowKeys([...$records, ...array_values($context->acceptedOf($this->resource))], $context);

        /** @var array<int, list<ContractIssue>> $issues */
        $issues = [];

        do {
            $failed = false;
            $standing = array_diff_key($records, $issues);
            $replaced = array_flip([...array_map(RecordFields::id(...), array_values($standing)), ...array_keys($acceptedKeys)]);

            foreach (array_keys($this->keys) as $field) {
                /** @var array<string, string> $holders key → id */
                $holders = [];

                foreach ($rowKeys as $id => $keys) {
                    if (! isset($replaced[$id]) && $keys[$field] !== null) {
                        $holders[$keys[$field]] ??= $id;
                    }
                }

                foreach ($acceptedKeys as $id => $keys) {
                    if ($keys[$field] !== null) {
                        $holders[$keys[$field]] ??= $id;
                    }
                }

                foreach (array_keys($standing) as $index) {
                    $key = $recordKeys[$index][$field];

                    if ($key === null) {
                        continue;
                    }

                    $holder = $holders[$key] ?? null;

                    if ($holder === null || $holder === $recordIds[$index]) {
                        $holders[$key] = $recordIds[$index];

                        continue;
                    }

                    $issues[$index][] = new ContractIssue(
                        '/'.$field,
                        self::KEYWORD,
                        sprintf('Another live %s has the same %s.', $this->resource, $field),
                        ['conflictingId' => $holder],
                    );
                    $failed = true;
                }
            }
        } while ($failed);

        return $issues;
    }

    /**
     * The record's value for each unique key; `null` for a tombstone and for a
     * key with a `null` part, which binds nothing (as in a MySQL unique index).
     *
     * @return array<string, string|null>
     */
    private function recordKeys(object $record): array
    {
        $tombstone = RecordFields::isTombstone($record);
        $keys = [];

        foreach ($this->keys as $field => $within) {
            $values = array_map(static fn (string $part): ?string => RecordFields::nullableString($record, $part), [...$within, $field]);
            $keys[$field] = $tombstone ? null : self::key($values);
        }

        return $keys;
    }

    /**
     * The server's live rows holding any of the keys the records claim, by
     * id: one query for every unique key of the resource.
     *
     * @param  list<object>  $records
     * @return array<string, array<string, string|null>>
     */
    private function livingRowKeys(array $records, RuleContext $context): array
    {
        $mapper = $this->registry->mapper($this->resource);
        $wanted = [];

        foreach ($records as $record) {
            if (RecordFields::isTombstone($record)) {
                continue;
            }

            foreach (array_keys($this->keys) as $field) {
                $value = RecordFields::nullableString($record, $field);

                if ($value !== null) {
                    $wanted[$mapper->field($field)->column][$value] = true;
                }
            }
        }

        if ($wanted === []) {
            return [];
        }

        $columns = [];

        foreach ($this->keys as $field => $within) {
            foreach ([...$within, $field] as $part) {
                $columns[$part] = $mapper->field($part)->column;
            }
        }

        // The column's collation may call more strings equal than the index
        // does; the keys compare byte for byte below.
        $rows = $this->registry->ownedModel($this->resource)::query()
            ->ownedBy($context->user)
            ->alive()
            ->where(static function (Builder $query) use ($wanted): void {
                foreach ($wanted as $column => $values) {
                    $query->orWhereIn($column, array_map(strval(...), array_keys($values)));
                }
            })
            ->get(['id', ...array_values($columns)]);

        $rowKeys = [];

        foreach ($rows as $row) {
            $keys = [];

            foreach ($this->keys as $field => $within) {
                $keys[$field] = self::key(array_map(
                    static fn (string $part): ?string => self::stringAttribute($row, $columns[$part]),
                    [...$within, $field],
                ));
            }

            $rowKeys[$row->id] = $keys;
        }

        return $rowKeys;
    }

    /**
     * An injective string for a tuple of strings; `null` if any part is null.
     *
     * @param  list<string|null>  $parts
     */
    private static function key(array $parts): ?string
    {
        $key = '';

        foreach ($parts as $part) {
            if ($part === null) {
                return null;
            }

            $key .= \strlen($part).':'.$part;
        }

        return $key;
    }

    private static function stringAttribute(SyncModel $row, string $column): ?string
    {
        $value = $row->getAttribute($column);

        return \is_string($value) ? $value : null;
    }
}
