<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Sync;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use TamasLabs\LaravelExpenses\Contract\ContractVersion;
use TamasLabs\LaravelExpenses\Registry\ResourceRegistry;

/**
 * Serves a pull (spec 06, 4.4): the changes after the cursor across every
 * resource the client knows, merged by `server_seq`, one page at a time.
 *
 * No transaction and no lock. Every query is bounded by the sequence number
 * committed when the pull starts: the rows up to it form a gapless prefix
 * (4.2), and each query sees all of them whenever it runs, so the cursor
 * never passes a row one of the queries missed.
 *
 * But the pruning may delete tombstones between the queries (spec 08, 3.3).
 * It raises the pruning mark before it deletes, so the mark is read again
 * after the queries: a cursor it passed may have missed a deletion.
 */
final class PullHandler
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly SyncStore $store,
    ) {}

    /**
     * @param  int  $limit  The most records the page holds.
     *
     * @throws ProtocolError When the cursor is older than the pruned tombstones.
     */
    public function pull(Authenticatable $user, Cursor $cursor, int $limit, ContractVersion $client): PullResult
    {
        ['value' => $through, 'prunedThrough' => $prunedThrough] = $this->store->sequenceState();

        if ($cursor->isOlderThan($prunedThrough)) {
            throw ProtocolError::cursorExpired();
        }

        /** @var list<array{int, string, Model}> $changes seq, resource, row */
        $changes = [];

        foreach ($this->registry->all() as $definition) {
            if (! $definition->knownBy($client)) {
                continue;
            }

            // One row past the page tells whether the resource has more.
            foreach ($this->store->changedSince($definition->name, $user, $cursor->seq, $through, $limit + 1) as $row) {
                $changes[] = [self::seq($row), $definition->name, $row];
            }
        }

        if ($cursor->isOlderThan($this->store->sequenceState()['prunedThrough'])) {
            throw ProtocolError::cursorExpired();
        }

        usort($changes, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        // Every row left out comes after the page's last one: a resource cut
        // at limit + 1 rows had its first limit + 1 in the merge.
        $hasMore = \count($changes) > $limit;
        $page = \array_slice($changes, 0, $limit);

        $this->store->loadPocketCategories($user, array_column($page, 2));

        $records = [];

        foreach ($page as [, $resource, $row]) {
            $records[$resource][] = $this->registry->mapper($resource)->toPayload($row);
        }

        $last = $page === [] ? null : $page[array_key_last($page)][0];

        return new PullResult(
            self::inRegistryOrder($this->registry->names(), $records),
            $last === null ? $cursor : Cursor::at($last),
            $hasMore,
        );
    }

    /**
     * @param  list<string>  $names
     * @param  array<string, non-empty-list<array<string, mixed>>>  $records
     * @return array<string, non-empty-list<array<string, mixed>>>
     */
    private static function inRegistryOrder(array $names, array $records): array
    {
        $ordered = [];

        foreach ($names as $name) {
            if (isset($records[$name])) {
                $ordered[$name] = $records[$name];
            }
        }

        return $ordered;
    }

    /**
     * @throws LogicException When the row has no sequence number.
     */
    private static function seq(Model $row): int
    {
        $seq = $row->getAttribute('server_seq');

        return \is_int($seq) ? $seq : throw new LogicException(sprintf('A %s row has no server_seq.', $row::class));
    }
}
