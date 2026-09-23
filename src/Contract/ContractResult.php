<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Contract;

/**
 * Outcome of validating one payload against the contract — the PHP mirror of
 * the TS `ExpensesValidationResult`.
 */
final readonly class ContractResult
{
    /**
     * @param  list<ContractIssue>  $issues  Empty when `valid`.
     */
    public function __construct(
        public bool $valid,
        public array $issues = [],
    ) {}

    /**
     * @param  string  $resource  Resource or protocol document name, for the error envelope.
     *
     * @throws ContractViolation
     */
    public function throwIfInvalid(string $resource): void
    {
        if (! $this->valid) {
            throw new ContractViolation($resource, $this->issues);
        }
    }

    /**
     * @return list<array{path: string, keyword: string, message: string, params?: array<string, string|int|float|bool|null>}>
     */
    public function issuesToArray(): array
    {
        return array_map(static fn (ContractIssue $issue): array => $issue->toArray(), $this->issues);
    }
}
