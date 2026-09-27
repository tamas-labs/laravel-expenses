<?php

declare(strict_types=1);

use TamasLabs\LaravelExpenses\Contract\ContractIssue;
use TamasLabs\LaravelExpenses\Rules\Record\ArchivedListHasTimestamp;
use TamasLabs\LaravelExpenses\Rules\Record\ExpenseAmountMatchesItems;
use TamasLabs\LaravelExpenses\Rules\Record\PriceSourceMatchesExpense;
use TamasLabs\LaravelExpenses\Rules\RecordRule;
use TamasLabs\LaravelExpenses\Tests\Support\ContractSchema;

// Spec 05, 4.5–4.7 — S4, S5, S6: rules on one record alone.

/**
 * @return list<array{path: string, keyword: string, message: string, params?: array<string, string|int|float|bool|null>}>
 */
function recordIssues(RecordRule $rule, stdClass $record): array
{
    return array_map(static fn (ContractIssue $issue): array => $issue->toArray(), iterator_to_array($rule->check($record), false));
}

/**
 * The expense example with items of the given totals.
 *
 * @param  array<mixed>  $totals
 */
function expenseWithItems(int|float $amountMinor, array $totals): stdClass
{
    $expense = ContractSchema::example('expense');
    $items = $expense->items;
    assert(is_array($items) && $items[0] instanceof stdClass);
    $template = $items[0];
    $expense->amountMinor = $amountMinor;
    $expense->items = array_map(static function (mixed $total) use ($template): stdClass {
        assert(is_int($total) || is_float($total));
        $item = clone $template;
        $item->quantity = 1;
        $item->unitPriceMinor = $total;
        $item->totalAmountMinor = $total;

        return $item;
    }, $totals);

    return $expense;
}

describe('S4 amountSum', function (): void {
    it('accepts an amount that is the sum of the items', function (int|float $amount, array $totals): void {
        expect(recordIssues(new ExpenseAmountMatchesItems, expenseWithItems($amount, $totals)))->toBe([]);
    })->with([
        'one item' => [129000, [129000]],
        'three items' => [6000, [1000, 2000, 3000]],
        'arrived as 1.0' => [6000.0, [1000, 2000.0, 3000]],
        'zero' => [0, [0, 0]],
    ]);

    it('accepts any amount without items', function (): void {
        expect(recordIssues(new ExpenseAmountMatchesItems, expenseWithItems(129000, [])))->toBe([]);
    });

    it('rejects an amount that is not the sum of the items', function (int $amount, array $totals, int $expected): void {
        expect(recordIssues(new ExpenseAmountMatchesItems, expenseWithItems($amount, $totals)))->toBe([[
            'path' => '/amountMinor',
            'keyword' => 'amountSum',
            'message' => 'The amount is not the sum of the items\' totalAmountMinor.',
            'params' => ['expected' => $expected],
        ]]);
    })->with([
        'one item' => [130000, [129000], 129000],
        'three items' => [5000, [1000, 2000, 3000], 6000],
        'items of zero' => [100, [0], 0],
    ]);

    it('checks the example as it is', function (): void {
        expect(recordIssues(new ExpenseAmountMatchesItems, ContractSchema::example('expense')))->toBe([]);
    });
});

describe('S5 sourceExpense', function (): void {
    it('pairs the source with the source expense', function (string $source, ?string $sourceExpenseId, ?string $message): void {
        $price = ContractSchema::example('product-price');
        $price->source = $source;
        $price->sourceExpenseId = $sourceExpenseId;

        $expected = $message === null ? [] : [['path' => '/sourceExpenseId', 'keyword' => 'sourceExpense', 'message' => $message]];

        expect(recordIssues(new PriceSourceMatchesExpense, $price))->toBe($expected);
    })->with([
        'expense with its expense' => ['expense', 'a3bb189e-8bf9-4888-9912-ace4e6543002', null],
        'expense without an expense' => ['expense', null, 'A price with the source "expense" needs a sourceExpenseId.'],
        'manual without an expense' => ['manual', null, null],
        'manual with an expense' => ['manual', 'a3bb189e-8bf9-4888-9912-ace4e6543002', 'Only a price with the source "expense" has a sourceExpenseId.'],
    ]);
});

describe('S6 archivedAt', function (): void {
    it('pairs the archived status with its timestamp', function (string $status, ?string $archivedAt, ?string $message): void {
        $list = ContractSchema::example('shopping-list');
        $list->status = $status;
        $list->archivedAt = $archivedAt;

        $expected = $message === null ? [] : [['path' => '/archivedAt', 'keyword' => 'archivedAt', 'message' => $message]];

        expect(recordIssues(new ArchivedListHasTimestamp, $list))->toBe($expected);
    })->with([
        'archived with a timestamp' => ['archived', '2026-09-14T08:30:00.000Z', null],
        'archived without a timestamp' => ['archived', null, 'An archived list needs an archivedAt.'],
        'active without a timestamp' => ['active', null, null],
        'active with a timestamp' => ['active', '2026-09-14T08:30:00.000Z', 'Only an archived list has an archivedAt.'],
    ]);
});
