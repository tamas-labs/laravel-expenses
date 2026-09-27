<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Rules\Record;

use TamasLabs\LaravelExpenses\Contract\ContractIssue;
use TamasLabs\LaravelExpenses\Rules\RecordFields;
use TamasLabs\LaravelExpenses\Rules\RecordRule;

/**
 * S6: a shopping list has an archivedAt exactly when it is archived.
 */
final class ArchivedListHasTimestamp implements RecordRule
{
    public const string KEYWORD = 'archivedAt';

    /**
     * @return list<ContractIssue>
     */
    public function check(object $record): array
    {
        $archived = RecordFields::string($record, 'status') === 'archived';
        $hasTimestamp = RecordFields::nullableString($record, 'archivedAt') !== null;

        if ($archived === $hasTimestamp) {
            return [];
        }

        return [new ContractIssue(
            '/archivedAt',
            self::KEYWORD,
            $archived
                ? 'An archived list needs an archivedAt.'
                : 'Only an archived list has an archivedAt.',
        )];
    }
}
