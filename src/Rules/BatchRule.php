<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Rules;

use TamasLabs\LaravelExpenses\Contract\ContractIssue;

/**
 * A domain rule that needs the server's state: it takes every pushed record
 * of one resource at once, and reads what it needs with at most one query per
 * table, whatever the number of records.
 */
interface BatchRule
{
    /**
     * @param  array<int, object>  $records  Index → a record the contract and the record rules have accepted.
     * @return array<int, list<ContractIssue>> The issues by index; a record without any may be left out.
     */
    public function check(array $records, RuleContext $context): array;
}
