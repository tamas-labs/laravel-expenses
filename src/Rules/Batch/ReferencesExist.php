<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Rules\Batch;

use LogicException;
use TamasLabs\LaravelExpenses\Contract\ContractIssue;
use TamasLabs\LaravelExpenses\Registry\ResourceRegistry;
use TamasLabs\LaravelExpenses\Registry\Scope;
use TamasLabs\LaravelExpenses\Rules\BatchRule;
use TamasLabs\LaravelExpenses\Rules\RecordFields;
use TamasLabs\LaravelExpenses\Rules\RuleContext;

/**
 * S1: every UUID a record refers to names an existing record of the same
 * user (a currency: of the global list).
 *
 * - A tombstone exists: the client keeps referring to a deleted category.
 * - A record the push accepted earlier exists; one it rejected does not, even
 *   when an older version of it is on the server.
 * - `null` refers to nothing.
 * - Another user's record gets the very same issue as a missing one, so the
 *   answer tells nothing about other users' ids.
 */
final class ReferencesExist implements BatchRule
{
    public const string KEYWORD = 'reference';

    /**
     * @param  string  $resource  The resource whose records are checked.
     * @param  array<string, string>  $references  Field → the resource it refers to; a
     *                                             list field (`categoryIds`) is checked item by item.
     */
    public function __construct(
        private readonly ResourceRegistry $registry,
        public readonly string $resource,
        public readonly array $references,
    ) {}

    public function check(array $records, RuleContext $context): array
    {
        /** @var list<array{int, string, string, string}> $references index, path, target, id */
        $references = [];

        foreach ($records as $index => $record) {
            foreach ($this->references as $field => $target) {
                foreach ($this->ids($record, $field) as $path => $id) {
                    $references[] = [$index, $path, $target, $id];
                }
            }
        }

        $lookup = [];

        foreach ($references as [, , $target, $id]) {
            if (! $context->isRejected($target, $id) && $context->accepted($target, $id) === null) {
                $lookup[$target][$id] = true;
            }
        }

        $found = [];

        foreach ($lookup as $target => $ids) {
            $found[$target] = $this->existingIds($target, array_map(strval(...), array_keys($ids)), $context);
        }

        $issues = [];

        foreach ($references as [$index, $path, $target, $id]) {
            $exists = ! $context->isRejected($target, $id)
                && ($context->accepted($target, $id) !== null || isset($found[$target][$id]));

            if (! $exists) {
                $issues[$index][] = new ContractIssue(
                    $path,
                    self::KEYWORD,
                    sprintf('The referenced %s does not exist.', $target),
                    ['resource' => $target, 'id' => $id],
                );
            }
        }

        return $issues;
    }

    /**
     * The ids a field refers to, by their JSON Pointer.
     *
     * @return array<string, string>
     */
    private function ids(object $record, string $field): array
    {
        $value = RecordFields::get($record, $field);

        if ($value === null) {
            return [];
        }

        if (\is_string($value)) {
            return ['/'.$field => $value];
        }

        if (! \is_array($value)) {
            throw new LogicException(sprintf('The domain rules cannot read %s as the reference "%s".', get_debug_type($value), $field));
        }

        $ids = [];

        foreach ($value as $position => $id) {
            $ids["/{$field}/{$position}"] = \is_string($id)
                ? $id
                : throw new LogicException(sprintf('"%s" holds a non-string reference: validate the record first.', $field));
        }

        return $ids;
    }

    /**
     * The given ids that exist on the server: one query per referenced table.
     *
     * @param  list<string>  $ids
     * @return array<string, true>
     */
    private function existingIds(string $target, array $ids, RuleContext $context): array
    {
        // The currencies are the only global list.
        if ($this->registry->get($target)->scope === Scope::Global) {
            return array_fill_keys(array_keys(array_intersect_key($context->currencies(), array_flip($ids))), true);
        }

        $model = $this->registry->ownedModel($target);
        $found = [];

        foreach ($model::query()->ownedBy($context->user)->whereIn('id', $ids)->pluck('id') as $id) {
            if (\is_string($id)) {
                $found[$id] = true;
            }
        }

        return $found;
    }
}
