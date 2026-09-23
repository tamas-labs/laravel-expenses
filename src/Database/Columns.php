<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use TamasLabs\LaravelExpenses\Support\PackageConfig;

/**
 * The column definitions the package's migrations repeat.
 *
 * Kept out of Blueprint macros, which would show up in every migration of the
 * host application.
 *
 * @internal
 */
final class Columns
{
    /**
     * A contract UUID: `char(36)`, ASCII with binary collation.
     *
     * Every UUID column shares this definition, because a foreign key needs
     * the same charset and collation on both ends.
     */
    public static function uuid(Blueprint $table, string $column): ColumnDefinition
    {
        return $table->char($column, 36)->charset('ascii')->collation('ascii_bin');
    }

    /**
     * A UUID pointing at the `id` of a global table (only `currencies` has one).
     */
    public static function uuidReference(Blueprint $table, string $column, string $parent): ColumnDefinition
    {
        $definition = self::uuid($table, $column);

        $table->foreign($column)
            ->references('id')
            ->on(PackageConfig::table($parent))
            ->restrictOnDelete()
            ->restrictOnUpdate();

        return $definition;
    }

    /**
     * The owning user, typed after the host's user key (`bigint unsigned`,
     * UUID or ULID).
     */
    public static function owner(Blueprint $table): void
    {
        $table->foreignIdFor(PackageConfig::userModel(), 'user_id')
            ->constrained()
            ->restrictOnDelete()
            ->restrictOnUpdate();
    }

    /**
     * A UUID pointing at a row of the same user: the foreign key is
     * `(user_id, column)` → `parent (user_id, id)`, since the seed UUIDs make
     * `id` alone ambiguous. A NULL reference is not checked (MATCH SIMPLE).
     */
    public static function ownedReference(Blueprint $table, string $column, string $parent): ColumnDefinition
    {
        $definition = self::uuid($table, $column);

        $table->foreign(['user_id', $column])
            ->references(['user_id', 'id'])
            ->on(PackageConfig::table($parent))
            ->restrictOnDelete()
            ->restrictOnUpdate();

        return $definition;
    }

    /**
     * The record's own timestamps (the client's values, UTC) and the server's
     * bookkeeping: the global sequence number and the time of the last write.
     *
     * @param  bool  $owned  Whether the table has a `user_id`; the pull reads
     *                       those by `(user_id, server_seq)`.
     */
    public static function syncColumns(Blueprint $table, bool $owned = true): void
    {
        $table->dateTime('created_at', 3);
        $table->dateTime('updated_at', 3);
        $table->dateTime('deleted_at', 3)->nullable();
        $table->unsignedBigInteger('server_seq')->unique();
        $table->dateTime('synced_at', 3);

        if ($owned) {
            $table->index(['user_id', 'server_seq']);
        }
    }

    /**
     * A stored generated column holding `$source` while the row is alive and
     * NULL once it is a tombstone, so a unique index on it only binds live
     * rows. The value is the SHA-256 of the source's UTF-8 bytes, which makes
     * the comparison byte-exact and works on `text` columns of any length;
     * pass `$plainLength` to copy a short ASCII source as is instead.
     */
    public static function activeKey(Blueprint $table, string $column, string $source, ?int $plainLength = null): ColumnDefinition
    {
        $definition = $plainLength === null
            ? $table->char($column, 64)->storedAs("IF(`deleted_at` IS NULL, SHA2(`{$source}`, 256), NULL)")
            : $table->string($column, $plainLength)->storedAs("IF(`deleted_at` IS NULL, `{$source}`, NULL)");

        return $definition->charset('ascii')->collation('ascii_bin')->nullable();
    }
}
