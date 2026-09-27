<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Sync;

use LogicException;
use TamasLabs\LaravelExpenses\Contract\ContractIssue;

/**
 * The outcome of one pushed record, in the push response.
 */
final readonly class RecordResult
{
    /**
     * @param  array<string, mixed>|null  $record  The server's version (stale only).
     * @param  list<ContractIssue>  $issues  Why it was refused (rejected only).
     */
    private function __construct(
        public string $id,
        public RecordStatus $status,
        public ?array $record = null,
        public array $issues = [],
    ) {}

    public static function accepted(string $id): self
    {
        return new self($id, RecordStatus::Accepted);
    }

    /**
     * @param  array<string, mixed>  $record  The server's version, as the mapper sends it.
     */
    public static function stale(string $id, array $record): self
    {
        return new self($id, RecordStatus::Stale, $record);
    }

    /**
     * @param  list<ContractIssue>  $issues
     *
     * @throws LogicException When there is no issue to tell.
     */
    public static function rejected(string $id, array $issues): self
    {
        if ($issues === []) {
            throw new LogicException(sprintf('The record %s cannot be rejected without an issue.', $id));
        }

        return new self($id, RecordStatus::Rejected, issues: $issues);
    }

    /**
     * The wire shape: `record` only when stale, `issues` only when rejected.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return match ($this->status) {
            RecordStatus::Accepted => ['id' => $this->id, 'status' => $this->status->value],
            RecordStatus::Stale => ['id' => $this->id, 'status' => $this->status->value, 'record' => $this->record],
            RecordStatus::Rejected => [
                'id' => $this->id,
                'status' => $this->status->value,
                'issues' => array_map(static fn (ContractIssue $issue): array => $issue->toArray(), $this->issues),
            ],
        };
    }
}
