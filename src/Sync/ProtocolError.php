<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Sync;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use TamasLabs\LaravelExpenses\Contract\ContractViolation;
use TamasLabs\LaravelExpenses\Support\Json;

/**
 * A sync request the server refuses as a whole (spec 06, 3.2 and 3.3), with
 * the contract's error code. A schema mismatch of the push envelope is a
 * {@see ContractViolation} instead.
 *
 * A client error, so it is never reported to the log.
 */
final class ProtocolError extends RuntimeException implements ShouldntReport
{
    private function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly ?string $resource = null,
    ) {
        parent::__construct($message);
    }

    public static function resourceNotWritable(string $resource): self
    {
        return new self('resource_not_writable', 422, sprintf('The %s resource cannot be pushed.', $resource), $resource);
    }

    public static function duplicateRecord(string $resource, string $id): self
    {
        return new self('duplicate_record', 422, sprintf('The %s record %s is pushed more than once.', $resource, $id), $resource);
    }

    public static function tooManyRecords(int $count, int $max): self
    {
        return new self('too_many_records', 422, sprintf('The push carries %d records; at most %d are allowed.', $count, $max));
    }

    public static function cursorMalformed(): self
    {
        return new self('cursor_malformed', 422, 'The cursor is not one the server issued.');
    }

    public static function cursorExpired(): self
    {
        return new self('cursor_expired', 410, 'The cursor is older than the retained deletions; download everything again.');
    }

    /**
     * The pull's `limit` is not a positive integer. There is no schema for a
     * query string, so this is the general contract error, without issues.
     */
    public static function limitMalformed(): self
    {
        return new self('contract_violation', 422, 'The limit must be a positive integer.');
    }

    /**
     * The `protocol/error` envelope.
     */
    public function render(): JsonResponse
    {
        $error = ['code' => $this->errorCode, 'message' => $this->getMessage()];

        if ($this->resource !== null) {
            $error['resource'] = $this->resource;
        }

        return new JsonResponse(['error' => $error], $this->status, [], Json::ENCODE_FLAGS);
    }
}
