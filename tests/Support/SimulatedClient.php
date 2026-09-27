<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Tests\Support;

use Carbon\CarbonImmutable;
use Closure;
use LogicException;
use stdClass;
use TamasLabs\LaravelExpenses\Mapping\RecordMapper;
use TamasLabs\LaravelExpenses\Support\Json;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;

/**
 * A device of a user, as the protocol asks a client to behave (spec 06, 6):
 * records in memory, the changed ones marked dirty until a push settles
 * them, and every pulled record merged by last-write-wins, the server
 * winning a tie.
 *
 * Its clock is the shared test clock plus its own offset, so devices disagree
 * on the time as real ones do. Its records are in the contract's canonical
 * form (the schema's key order, `…Z` timestamps, sorted `categoryIds`), which
 * is how the server sends them back.
 */
final class SimulatedClient
{
    /**
     * @var array<string, array<string, stdClass>> resource → id → record
     */
    private array $records = [];

    /**
     * @var array<string, array<string, true>>
     */
    private array $dirty = [];

    private ?string $cursor = null;

    /**
     * Every rejection the server answered with, as `resource id: issues`.
     *
     * @var list<string>
     */
    public array $rejections = [];

    /**
     * How often each branch of the protocol ran: the push's `stale`, and the
     * pull merge keeping a later local version or losing a tie.
     *
     * @var array{stale: int, keptLocal: int, lostTie: int}
     */
    public array $branches = ['stale' => 0, 'keptLocal' => 0, 'lostTie' => 0];

    /**
     * @param  Closure(): int  $clock  The shared clock, in Unix milliseconds.
     * @param  int  $offset  How far this device's clock is off, in milliseconds.
     * @param  int  $pageSize  The `limit` of its pulls.
     */
    public function __construct(
        public readonly User $user,
        private readonly Closure $clock,
        private readonly int $offset,
        private readonly int $pageSize,
    ) {}

    /**
     * The device's time, in the contract's form.
     */
    public function now(): string
    {
        return CarbonImmutable::createFromTimestampMsUTC(($this->clock)() + $this->offset)->format(RecordMapper::TIMESTAMP_FORMAT);
    }

    /**
     * Creates a record; its timestamps are set here.
     */
    public function create(string $resource, stdClass $record): stdClass
    {
        $record->createdAt = $this->now();
        $record->updatedAt = $record->createdAt;
        $record->deletedAt = null;

        return $this->save($resource, $record);
    }

    /**
     * Changes a record's fields, with a new `updatedAt`.
     *
     * @param  array<string, mixed>  $fields
     */
    public function change(string $resource, string $id, array $fields): stdClass
    {
        $record = clone ($this->records[$resource][$id] ?? throw new LogicException("There is no {$resource} {$id}."));

        foreach ($fields as $field => $value) {
            $record->{$field} = $value;
        }

        $record->updatedAt = $this->now();

        return $this->save($resource, $record);
    }

    public function delete(string $resource, string $id): stdClass
    {
        return $this->change($resource, $id, ['deletedAt' => $this->now()]);
    }

    /**
     * @return list<stdClass>
     */
    public function records(string $resource, ?bool $alive = null): array
    {
        return array_values(array_filter(
            $this->records[$resource] ?? [],
            static fn (stdClass $record): bool => $alive === null || ($record->deletedAt === null) === $alive,
        ));
    }

    public function hasDirty(): bool
    {
        return array_filter($this->dirty) !== [];
    }

    /**
     * Pushes every dirty record and settles each by its result (6/3–4).
     */
    public function push(): void
    {
        $push = [];

        foreach ($this->dirty as $resource => $ids) {
            foreach (array_keys($ids) as $id) {
                $push[$resource][] = $this->records[$resource][$id];
            }
        }

        if ($push === []) {
            return;
        }

        $results = SyncApi::pushOk($this->user, $push);

        foreach ($push as $resource => $records) {
            foreach ($records as $index => $record) {
                $result = SyncApi::result($results, $resource, $index);

                match ($result->status) {
                    'accepted' => $this->clean($resource, self::id($record)),
                    'stale' => $this->stale($resource, self::record($result->record ?? null)),
                    default => $this->rejections[] = $resource.' '.self::id($record).': '.Json::encode($result->issues ?? null),
                };
            }
        }
    }

    /**
     * Pulls every page (6/6) and merges each record by last-write-wins, the
     * server's version staying on a tie (6/7).
     */
    public function pull(): void
    {
        $pages = 0;

        do {
            $page = SyncApi::pullOk($this->user, $this->cursor, $this->pageSize);

            foreach (get_object_vars(SyncApi::records($page)) as $resource => $records) {
                foreach (\is_array($records) ? $records : [] as $record) {
                    $this->merge((string) $resource, self::record($record));
                }
            }

            $this->cursor = \is_string($page->cursor ?? null) ? $page->cursor : throw new LogicException('A page without a cursor.');

            if (++$pages > 1000) {
                throw new LogicException('The pull does not end.');
            }
        } while (($page->hasMore ?? null) === true);
    }

    /**
     * Every record held, encoded, by resource and id (both sorted).
     *
     * @return array<string, array<string, string>>
     */
    public function state(): array
    {
        $state = [];

        foreach ($this->records as $resource => $records) {
            foreach ($records as $id => $record) {
                $state[$resource][$id] = Json::encode($record);
            }

            ksort($state[$resource]);
        }

        ksort($state);

        return $state;
    }

    private function merge(string $resource, stdClass $incoming): void
    {
        $local = $this->records[$resource][self::id($incoming)] ?? null;

        if ($local === null) {
            $this->take($resource, $incoming);

            return;
        }

        $order = self::millis($incoming) <=> self::millis($local);

        if ($order > 0) {
            $this->take($resource, $incoming);
        } elseif ($order < 0) {
            // A later local version stays, dirty, for the next push.
            $this->branches['keptLocal']++;
        } elseif (Json::encode($incoming) !== Json::encode($local)) {
            $this->branches['lostTie']++;
            $this->take($resource, $incoming);
        } else {
            // The server holds this very version already.
            $this->clean($resource, self::id($incoming));
        }
    }

    private function stale(string $resource, stdClass $record): void
    {
        $this->branches['stale']++;
        $this->take($resource, $record);
    }

    private function save(string $resource, stdClass $record): stdClass
    {
        $this->records[$resource][self::id($record)] = $record;
        $this->dirty[$resource][self::id($record)] = true;

        return $record;
    }

    private function take(string $resource, stdClass $record): void
    {
        $this->records[$resource][self::id($record)] = $record;
        $this->clean($resource, self::id($record));
    }

    private function clean(string $resource, string $id): void
    {
        unset($this->dirty[$resource][$id]);
    }

    private static function millis(stdClass $record): int
    {
        return CarbonImmutable::parse(\is_string($record->updatedAt ?? null) ? $record->updatedAt : throw new LogicException('A record without updatedAt.'))->getTimestampMs();
    }

    private static function record(mixed $record): stdClass
    {
        return $record instanceof stdClass ? $record : throw new LogicException('The server sent a non-object as a record.');
    }

    private static function id(stdClass $record): string
    {
        return \is_string($record->id ?? null) ? $record->id : throw new LogicException('A record without an id.');
    }
}
