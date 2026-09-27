<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Sync;

/**
 * Which version of a record a last-write-wins comparison keeps.
 */
enum LwwOutcome
{
    /** The pushed record is new or newer: it is written. */
    case PushedWins;

    /** Same `updatedAt`, same content: nothing to write. */
    case Unchanged;

    /** The server's version is newer, or wins the tie. */
    case ServerWins;
}
