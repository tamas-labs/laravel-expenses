<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Contract;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use TamasLabs\LaravelExpenses\Support\Json;

/**
 * A whole request does not match the contract (e.g. the push envelope).
 *
 * Record-level failures inside a valid envelope are not exceptions; they are
 * per-record results (phase 06). This is a client error, so it is never
 * reported to the log.
 */
final class ContractViolation extends RuntimeException implements ShouldntReport
{
    public const string CODE = 'contract_violation';

    /**
     * @param  string  $resource  Resource or protocol document name.
     * @param  list<ContractIssue>  $issues
     */
    public function __construct(
        public readonly string $resource,
        public readonly array $issues,
    ) {
        parent::__construct(sprintf('The payload does not match the %s contract.', $resource));
    }

    /**
     * The `protocol/error` envelope with status 422.
     */
    public function render(): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => self::CODE,
                'message' => $this->getMessage(),
                'resource' => $this->resource,
                'issues' => array_map(static fn (ContractIssue $issue): array => $issue->toArray(), $this->issues),
            ],
        ], 422, [], Json::ENCODE_FLAGS);
    }
}
