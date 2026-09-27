<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Rules\Record;

use LogicException;
use TamasLabs\LaravelExpenses\Contract\ContractIssue;
use TamasLabs\LaravelExpenses\Rules\RecordFields;
use TamasLabs\LaravelExpenses\Rules\RecordRule;

/**
 * S4: an expense with items costs what its items cost together (the
 * `expense` schema's `amountMinor` description).
 */
final class ExpenseAmountMatchesItems implements RecordRule
{
    public const string KEYWORD = 'amountSum';

    /**
     * @return list<ContractIssue>
     */
    public function check(object $record): array
    {
        $items = RecordFields::get($record, 'items');

        if (! \is_array($items)) {
            throw new LogicException('The expense\'s items are not a list: validate the record first.');
        }

        if ($items === []) {
            return [];
        }

        $expected = 0;

        foreach ($items as $item) {
            if (! \is_object($item)) {
                throw new LogicException('An expense item is not an object: validate the record first.');
            }

            // Past PHP_INT_MAX the sum turns into a float, which no amount matches.
            $expected += RecordFields::integer($item, 'totalAmountMinor');
        }

        if (RecordFields::integer($record, 'amountMinor') === $expected) {
            return [];
        }

        return [new ContractIssue(
            '/amountMinor',
            self::KEYWORD,
            'The amount is not the sum of the items\' totalAmountMinor.',
            ['expected' => $expected],
        )];
    }
}
