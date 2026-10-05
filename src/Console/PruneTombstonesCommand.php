<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Console;

use Illuminate\Console\Command;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Sync\TombstonePruner;

/**
 * `php artisan expenses:prune-tombstones [--days=] [--dry-run]` (spec 08, 3.3).
 * The host schedules it; the package schedules nothing.
 */
final class PruneTombstonesCommand extends Command
{
    /** @var string */
    protected $signature = 'expenses:prune-tombstones
        {--days= : Keep the tombstones stored in the last this many days (default: expenses.pruning.tombstone_days)}
        {--dry-run : Only count the tombstones that would be deleted}';

    /** @var string */
    protected $description = 'Delete the old tombstones no record refers to; older cursors get 410 cursor_expired';

    public function handle(TombstonePruner $pruner): int
    {
        $days = $this->days();

        if ($days === null) {
            $this->components->error('The --days option must be a positive integer.');

            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');
        $report = $pruner->prune($days, $dryRun);

        $this->components->info(sprintf(
            $dryRun ? 'Tombstones older than %d days that would be deleted:' : 'Tombstones older than %d days deleted:',
            $days,
        ));

        foreach ($report['deleted'] as $resource => $count) {
            $this->components->twoColumnDetail($resource, (string) $count);
        }

        $this->components->twoColumnDetail('pruned_through', (string) $report['prunedThrough']);

        return self::SUCCESS;
    }

    private function days(): ?int
    {
        $option = $this->option('days');

        if ($option === null) {
            return PackageConfig::tombstoneDays();
        }

        return preg_match('/\A[1-9][0-9]{0,5}\z/', $option) === 1 ? (int) $option : null;
    }
}
