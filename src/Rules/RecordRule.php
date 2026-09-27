<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Rules;

use TamasLabs\LaravelExpenses\Contract\ContractIssue;

/**
 * A domain rule on one record alone: no database, no other record.
 */
interface RecordRule
{
    /**
     * @param  object  $record  A record the contract has accepted, decoded with objects.
     * @return iterable<ContractIssue>
     */
    public function check(object $record): iterable;
}
