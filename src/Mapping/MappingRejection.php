<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Mapping;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;
use TamasLabs\LaravelExpenses\Contract\ContractIssue;

/**
 * A valid record the server still cannot store as sent, e.g. a timestamp on a
 * leap second (the schema's `date-time` allows it, `datetime(3)` does not).
 *
 * The sync turns it into a per-record result carrying the issue; it is the
 * client's error, so it is never reported to the log.
 */
final class MappingRejection extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly ContractIssue $issue)
    {
        parent::__construct(sprintf('%s: %s', $issue->path, $issue->message));
    }
}
