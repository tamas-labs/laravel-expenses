<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Rules;

use InvalidArgumentException;
use TamasLabs\LaravelExpenses\Contract\ContractIssue;
use TamasLabs\LaravelExpenses\Registry\ResourceRegistry;
use TamasLabs\LaravelExpenses\Rules\Batch\CurrencyNotRetired;
use TamasLabs\LaravelExpenses\Rules\Batch\ReferencesExist;
use TamasLabs\LaravelExpenses\Rules\Batch\UniqueAmongLiving;
use TamasLabs\LaravelExpenses\Rules\Record\ArchivedListHasTimestamp;
use TamasLabs\LaravelExpenses\Rules\Record\ExpenseAmountMatchesItems;
use TamasLabs\LaravelExpenses\Rules\Record\PriceSourceMatchesExpense;

/**
 * The domain rules of every pushable resource (spec 05): what the server
 * checks beyond the schema before it stores a record.
 *
 * Only what the client guarantees too is a rule; anything else would reject
 * a record the client rightly sends, again on every sync. Every pushable
 * resource has an entry here, even an empty one (a test holds it), so a new
 * resource cannot skip the rules unnoticed. Bound as a singleton.
 */
final class DomainValidator
{
    /**
     * @var array<string, array{list<RecordRule>, list<BatchRule>}>
     */
    private readonly array $rules;

    public function __construct(ResourceRegistry $registry)
    {
        $this->rules = [
            'category' => [[], [
                new UniqueAmongLiving($registry, 'category', ['name' => []]),
            ]],
            'subcategory' => [[], [
                new ReferencesExist($registry, 'subcategory', ['categoryId' => 'category']),
                new UniqueAmongLiving($registry, 'subcategory', ['name' => ['categoryId']]),
            ]],
            'payment-method' => [[], [
                new UniqueAmongLiving($registry, 'payment-method', ['name' => []]),
            ]],
            'expense' => [[new ExpenseAmountMatchesItems], [
                new ReferencesExist($registry, 'expense', [
                    'mainCategoryId' => 'category',
                    'subCategoryId' => 'subcategory',
                    'paymentMethodId' => 'payment-method',
                    'currencyId' => 'currency',
                ]),
                new CurrencyNotRetired($registry, 'expense'),
            ]],
            'product' => [[], [
                new ReferencesExist($registry, 'product', ['mainCategoryId' => 'category', 'subCategoryId' => 'subcategory']),
                new UniqueAmongLiving($registry, 'product', ['normalizedName' => [], 'barcode' => []]),
            ]],
            'product-price' => [[new PriceSourceMatchesExpense], [
                new ReferencesExist($registry, 'product-price', [
                    'productId' => 'product',
                    'sourceExpenseId' => 'expense',
                    'currencyId' => 'currency',
                ]),
                new CurrencyNotRetired($registry, 'product-price'),
            ]],
            'shopping-list' => [[new ArchivedListHasTimestamp], []],
            'shopping-list-item' => [[], [
                new ReferencesExist($registry, 'shopping-list-item', ['shoppingListId' => 'shopping-list']),
            ]],
            'budget-pocket' => [[], [
                new ReferencesExist($registry, 'budget-pocket', ['categoryIds' => 'category']),
            ]],
        ];
    }

    /**
     * Checks the pushed records of one resource: the record rules first, then
     * the batch rules on the records that passed them. Every issue of a
     * record comes back, from every rule it breaks.
     *
     * @param  array<int, object>  $records  Index → a record the contract has accepted.
     * @return array<int, list<ContractIssue>> Index → issues; an empty list lets the record through.
     *
     * @throws InvalidArgumentException When the resource has no rules (it is not pushable).
     */
    public function validate(string $resource, array $records, RuleContext $context): array
    {
        [$recordRules, $batchRules] = $this->rulesOf($resource);

        $issues = [];
        $passed = [];

        foreach ($records as $index => $record) {
            $issues[$index] = [];

            foreach ($recordRules as $rule) {
                foreach ($rule->check($record) as $issue) {
                    $issues[$index][] = $issue;
                }
            }

            if ($issues[$index] === []) {
                $passed[$index] = $record;
            }
        }

        if ($passed === []) {
            return $issues;
        }

        foreach ($batchRules as $rule) {
            foreach ($rule->check($passed, $context) as $index => $found) {
                $issues[$index] = [...$issues[$index], ...$found];
            }
        }

        return $issues;
    }

    /**
     * The resources that have rules.
     *
     * @return list<string>
     */
    public function resources(): array
    {
        return array_keys($this->rules);
    }

    /**
     * The fields the resource's unique keys (S2) are made of, the fields they
     * are unique within included: a row changing any of them may move to
     * another key.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException When the resource has no rules.
     */
    public function uniqueFields(string $resource): array
    {
        $fields = [];

        foreach ($this->batchRules($resource) as $rule) {
            if ($rule instanceof UniqueAmongLiving) {
                foreach ($rule->keys as $field => $within) {
                    array_push($fields, $field, ...$within);
                }
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * @return list<RecordRule>
     *
     * @throws InvalidArgumentException When the resource has no rules.
     */
    public function recordRules(string $resource): array
    {
        return $this->rulesOf($resource)[0];
    }

    /**
     * @return list<BatchRule>
     *
     * @throws InvalidArgumentException When the resource has no rules.
     */
    public function batchRules(string $resource): array
    {
        return $this->rulesOf($resource)[1];
    }

    /**
     * @return array{list<RecordRule>, list<BatchRule>}
     *
     * @throws InvalidArgumentException When the resource has no rules.
     */
    private function rulesOf(string $resource): array
    {
        return $this->rules[$resource] ?? throw new InvalidArgumentException(sprintf('There are no domain rules for "%s": it is not pushable.', $resource));
    }
}
