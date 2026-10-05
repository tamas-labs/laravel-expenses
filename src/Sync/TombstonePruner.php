<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Sync;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use TamasLabs\LaravelExpenses\Registry\ResourceRegistry;
use TamasLabs\LaravelExpenses\Support\PackageConfig;

/**
 * Deletes the old tombstones for good (spec 08, 3.3).
 *
 * A tombstone goes once the server stored it more than the retention ago
 * (the server's `synced_at`, not the client's `deletedAt`) and no row refers
 * to it. The resources go against the push order, so a run that deletes the
 * children frees their parents too. The work is done in batches, each in a
 * transaction of its own, under the locks of the rows' owners.
 *
 * Before a batch goes, `pruned_through` is raised to its highest
 * `server_seq`: a client whose cursor is older gets 410 `cursor_expired` and
 * downloads everything again (06, 4.6). Raised first, so no pull can miss a
 * deletion without hearing about it (the pull checks the mark again after
 * its reads). Bound as a singleton; it keeps no state.
 */
final class TombstonePruner
{
    /**
     * The tombstones one transaction deletes at most.
     */
    public const int BATCH = 1000;

    /**
     * Deadlocks and lock wait timeouts retry a batch.
     */
    private const int ATTEMPTS = 3;

    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly SyncStore $store,
    ) {}

    /**
     * @param  int  $days  The retention: tombstones stored longer ago go.
     * @param  bool  $dryRun  Only count what would go.
     * @return array{deleted: array<string, int>, prunedThrough: int} Resource → tombstones deleted (or to delete), in the order of the run.
     */
    public function prune(int $days, bool $dryRun = false): array
    {
        $before = CarbonImmutable::now('UTC')->subDays($days);
        $deleted = [];

        foreach (array_reverse($this->registry->pushable()) as $definition) {
            $deleted[$definition->name] = $dryRun
                ? $this->store->countPrunableTombstones($definition->name, $before)
                : $this->pruneResource($definition->name, $before);
        }

        $report = ['deleted' => $deleted, 'prunedThrough' => $this->store->sequenceState()['prunedThrough']];

        if (! $dryRun) {
            Log::channel(PackageConfig::logChannel())->info('Expenses tombstones pruned.', [...$report, 'days' => $days]);
        }

        return $report;
    }

    private function pruneResource(string $resource, CarbonImmutable $before): int
    {
        $deleted = 0;
        $after = 0;

        do {
            $batch = $this->store->prunableTombstones($resource, $before, $after, self::BATCH);

            if ($batch === []) {
                break;
            }

            $seqs = array_column($batch, 0);
            $after = max($seqs);

            $this->store->raisePrunedThrough($after);

            $deleted += \count(DB::transaction(
                fn (): array => $this->store->deleteTombstones($resource, $before, $seqs, array_column($batch, 1)),
                self::ATTEMPTS,
            ));
        } while (\count($batch) === self::BATCH);

        return $deleted;
    }
}
