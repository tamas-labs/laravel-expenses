<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Rules\Record;

use TamasLabs\LaravelExpenses\Contract\ContractIssue;
use TamasLabs\LaravelExpenses\Rules\RecordFields;
use TamasLabs\LaravelExpenses\Rules\RecordRule;

/**
 * S5: a price taken from an expense names it, a manual price names none.
 */
final class PriceSourceMatchesExpense implements RecordRule
{
    public const string KEYWORD = 'sourceExpense';

    /**
     * @return list<ContractIssue>
     */
    public function check(object $record): array
    {
        $fromExpense = RecordFields::string($record, 'source') === 'expense';
        $hasExpense = RecordFields::nullableString($record, 'sourceExpenseId') !== null;

        if ($fromExpense === $hasExpense) {
            return [];
        }

        return [new ContractIssue(
            '/sourceExpenseId',
            self::KEYWORD,
            $fromExpense
                ? 'A price with the source "expense" needs a sourceExpenseId.'
                : 'Only a price with the source "expense" has a sourceExpenseId.',
        )];
    }
}
