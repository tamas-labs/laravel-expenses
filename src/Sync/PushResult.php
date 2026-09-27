<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Sync;

use JsonSerializable;
use stdClass;

/**
 * The answer to a push: one result per pushed record, grouped and ordered as
 * the request was.
 */
final readonly class PushResult implements JsonSerializable
{
    /**
     * @param  array<string, list<RecordResult>>  $results  Resource → results, in the request's order.
     * @param  array<string, int>  $written  Resource → the rows the push wrote; only resources with any.
     */
    public function __construct(
        public array $results,
        public array $written,
    ) {}

    /**
     * The `push-response` document. `results` is an object even when the
     * push was empty.
     *
     * @return array{results: stdClass}
     */
    public function jsonSerialize(): array
    {
        $results = new stdClass;

        foreach ($this->results as $resource => $group) {
            $results->{$resource} = array_map(static fn (RecordResult $result): array => $result->toArray(), $group);
        }

        return ['results' => $results];
    }
}
