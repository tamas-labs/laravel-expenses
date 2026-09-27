<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Rules;

use Illuminate\Contracts\Auth\Authenticatable;
use LogicException;
use TamasLabs\LaravelExpenses\Models\SyncModel;
use TamasLabs\LaravelExpenses\Sync\SyncStore;

/**
 * What the rules know about one push beyond the server's tables: the user,
 * the records the push has accepted or rejected so far, and the server's
 * version of the records being checked. The tables themselves are read
 * through the {@see SyncStore}.
 *
 * The sync (spec 06) fills it in as it goes, resource by resource in the
 * push order: a record accepted earlier exists for the resources after it.
 */
final class RuleContext
{
    /**
     * @var array<string, array<string, object>>
     */
    private array $accepted = [];

    /**
     * @var array<string, array<string, true>>
     */
    private array $rejected = [];

    /**
     * @var array<string, array<string, SyncModel>>
     */
    private array $existing = [];

    /**
     * Currency id → whether it is retired; loaded once per push.
     *
     * @var array<string, bool>|null
     */
    private ?array $currencies = null;

    public function __construct(
        public readonly Authenticatable $user,
        public readonly SyncStore $store,
    ) {}

    /**
     * A record of the push passed; it exists (and, if alive, holds its unique
     * keys) for the records checked after it.
     */
    public function accept(string $resource, object $record): void
    {
        $this->accepted[$resource][RecordFields::id($record)] = $record;
    }

    /**
     * A record of the push failed: a record referring to it fails too, even
     * if an older version of it is on the server.
     */
    public function reject(string $resource, string $id): void
    {
        $this->rejected[$resource][$id] = true;
    }

    /**
     * The server's current version of the resource's pushed records, which
     * the sync loads for the last-write-wins decision anyway.
     *
     * @param  array<string, SyncModel>  $rows  id → row; a new record has none.
     */
    public function setExisting(string $resource, array $rows): void
    {
        $this->existing[$resource] = $rows;
    }

    public function accepted(string $resource, string $id): ?object
    {
        return $this->accepted[$resource][$id] ?? null;
    }

    /**
     * @return array<string, object> id → record
     */
    public function acceptedOf(string $resource): array
    {
        return $this->accepted[$resource] ?? [];
    }

    public function isRejected(string $resource, string $id): bool
    {
        return isset($this->rejected[$resource][$id]);
    }

    /**
     * @return array<string, SyncModel> id → row
     *
     * @throws LogicException When the sync has not loaded them.
     */
    public function existing(string $resource): array
    {
        return $this->existing[$resource] ?? throw new LogicException(sprintf(
            'The rule context has no server rows for the %s records: set them before running the rules.',
            $resource,
        ));
    }

    /**
     * Every currency, retired or not: the list is global and short, so one
     * query serves every rule of the push.
     *
     * @return array<string, bool> id → retired
     */
    public function currencies(): array
    {
        return $this->currencies ??= $this->store->currencies();
    }
}
