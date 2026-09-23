<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Database;

use Illuminate\Support\Facades\DB;
use LogicException;
use TamasLabs\LaravelExpenses\Support\PackageConfig;

/**
 * The single-row counter every `server_seq` is taken from.
 *
 * Values start at 1; a pull cursor of 0 means "from the beginning".
 *
 * @internal
 */
final class SyncSequence
{
    public const string TABLE = 'sync_sequence';

    /**
     * Reserves `$count` consecutive sequence numbers and returns the last one:
     * the caller owns `(last - count, last]`.
     *
     * The UPDATE locks the counter row until the surrounding transaction
     * commits, so transactions that reserve numbers commit in the order of
     * their numbers (spec 06, 4.2). Outside a transaction that guarantee, and
     * the value read back, would not hold.
     *
     * @throws LogicException When no transaction is open, or the counter row is missing.
     */
    public static function reserve(int $count): int
    {
        if ($count < 1) {
            throw new LogicException('At least one sequence number must be reserved.');
        }

        if (DB::transactionLevel() === 0) {
            throw new LogicException('Sequence numbers can only be reserved inside a transaction.');
        }

        $table = DB::table(PackageConfig::table(self::TABLE))->where('id', 1);

        if ($table->increment('value', $count) !== 1) {
            throw new LogicException('The sync_sequence row is missing; run the package migrations.');
        }

        $last = $table->value('value');

        if (! \is_int($last)) {
            throw new LogicException(sprintf('The sync_sequence value must be an integer; got %s.', get_debug_type($last)));
        }

        return $last;
    }
}
