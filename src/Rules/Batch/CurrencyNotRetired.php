<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Rules\Batch;

use TamasLabs\LaravelExpenses\Contract\ContractIssue;
use TamasLabs\LaravelExpenses\Registry\ResourceRegistry;
use TamasLabs\LaravelExpenses\Rules\BatchRule;
use TamasLabs\LaravelExpenses\Rules\RecordFields;
use TamasLabs\LaravelExpenses\Rules\RuleContext;

/**
 * S3: a retired currency (one with a `deletedAt`) cannot be chosen anew — the
 * `currency` schema: existing references stay valid, a new record cannot
 * choose it.
 *
 * A record fails when it is new on the server or changes its `currencyId`,
 * and the currency it names is retired. A record keeping the currency it had
 * passes, and so does a tombstone. An unknown currency is S1's to report.
 */
final class CurrencyNotRetired implements BatchRule
{
    public const string KEYWORD = 'retired';

    private const string FIELD = 'currencyId';

    /**
     * @param  string  $resource  The resource whose records are checked.
     */
    public function __construct(
        private readonly ResourceRegistry $registry,
        public readonly string $resource,
    ) {}

    public function check(array $records, RuleContext $context): array
    {
        $existing = $context->existing($this->resource);
        $currencies = $context->currencies();
        $column = $this->registry->mapper($this->resource)->field(self::FIELD)->column;
        $issues = [];

        foreach ($records as $index => $record) {
            $currencyId = RecordFields::string($record, self::FIELD);

            if (RecordFields::isTombstone($record) || ! ($currencies[$currencyId] ?? false)) {
                continue;
            }

            $stored = $existing[RecordFields::id($record)] ?? null;

            if ($stored !== null && $stored->getAttribute($column) === $currencyId) {
                continue;
            }

            $issues[$index][] = new ContractIssue(
                '/'.self::FIELD,
                self::KEYWORD,
                'The currency is retired: only a record that already had it may keep it.',
                ['id' => $currencyId],
            );
        }

        return $issues;
    }
}
