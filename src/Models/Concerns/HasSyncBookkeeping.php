<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Models\Concerns;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use TamasLabs\LaravelExpenses\Database\Casts\UtcDateTime;
use TamasLabs\LaravelExpenses\Support\PackageConfig;

/**
 * What every synced table shares: the prefixed table name, the client's
 * timestamps, the server's sequence number and the scopes the pull reads by.
 *
 * `deleted_at` is the client's tombstone mark, not Laravel's SoftDeletes: the
 * pull has to see tombstones, so nothing hides them globally.
 *
 * @phpstan-require-extends Model
 */
trait HasSyncBookkeeping
{
    public function initializeHasSyncBookkeeping(): void
    {
        $this->mergeCasts([
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
            'deleted_at' => UtcDateTime::class,
            'server_seq' => 'integer',
            'synced_at' => UtcDateTime::class,
        ]);
    }

    public function getTable(): string
    {
        return PackageConfig::table(parent::getTable());
    }

    /**
     * Rows that are not tombstones.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function alive(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('deleted_at'));
    }

    /**
     * Rows written after the given pull cursor, oldest first.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function changedSince(Builder $query, int $cursor): void
    {
        $query->where($query->qualifyColumn('server_seq'), '>', $cursor)
            ->orderBy($query->qualifyColumn('server_seq'));
    }
}
