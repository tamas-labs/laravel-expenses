<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Sync;

use JsonSerializable;
use stdClass;

/**
 * One page of a pull: the records changed since the cursor, the cursor to
 * continue from, and whether more pages follow.
 */
final readonly class PullResult implements JsonSerializable
{
    /**
     * @param  array<string, non-empty-list<array<string, mixed>>>  $records  Resource → payloads, in the registry's order; no empty group.
     */
    public function __construct(
        public array $records,
        public Cursor $cursor,
        public bool $hasMore,
    ) {}

    /**
     * The `pull-response` document. `records` is an object even when empty.
     *
     * @return array{records: stdClass, cursor: string, hasMore: bool}
     */
    public function jsonSerialize(): array
    {
        $records = new stdClass;

        foreach ($this->records as $resource => $group) {
            $records->{$resource} = $group;
        }

        return ['records' => $records, 'cursor' => (string) $this->cursor, 'hasMore' => $this->hasMore];
    }
}
