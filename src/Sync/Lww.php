<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Sync;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use TamasLabs\LaravelExpenses\Mapping\RecordMapper;
use TamasLabs\LaravelExpenses\Support\Json;

/**
 * The last-write-wins rule (spec 06, 5.1), which the client applies the same
 * way when it pulls, so both sides settle on the same version:
 *
 * 1. no server version → the pushed one wins
 * 2. the later `updatedAt` wins, compared as instants to the millisecond
 * 3. same `updatedAt`, same content → nothing changes
 * 4. same `updatedAt`, other content → the server's version wins
 *
 * A tombstone is a change like any other: it competes with its own
 * `updatedAt`, and a later live version brings the record back.
 */
final class Lww
{
    /**
     * @param  Model  $pushed  The pushed record as a model, not saved.
     * @param  Model|null  $server  The server's version; `null` for a new record.
     *
     * @throws LogicException When a model has no `updatedAt`.
     */
    public static function decide(Model $pushed, ?Model $server, RecordMapper $mapper): LwwOutcome
    {
        if ($server === null) {
            return LwwOutcome::PushedWins;
        }

        $column = $mapper->field('updatedAt')->column;
        $order = self::millis($pushed, $column) <=> self::millis($server, $column);

        if ($order !== 0) {
            return $order > 0 ? LwwOutcome::PushedWins : LwwOutcome::ServerWins;
        }

        return self::sameContent($pushed, $server, $mapper) ? LwwOutcome::Unchanged : LwwOutcome::ServerWins;
    }

    /**
     * Whether the two versions send the same bytes (spec 06, 5.2): the
     * mapper's payload fixes the key order, the timestamps' form and the
     * order of a pocket's categories.
     */
    public static function sameContent(Model $a, Model $b, RecordMapper $mapper): bool
    {
        return Json::encode($mapper->toPayload($a)) === Json::encode($mapper->toPayload($b));
    }

    /**
     * @throws LogicException When the attribute holds no date.
     */
    private static function millis(Model $model, string $column): int
    {
        $instant = $model->getAttribute($column);

        if (! $instant instanceof DateTimeInterface) {
            throw new LogicException(sprintf('The %s attribute of %s holds no date.', $column, $model::class));
        }

        // The seconds are floored, so the milliseconds add up before 1970 too.
        return $instant->getTimestamp() * 1000 + (int) $instant->format('v');
    }
}
