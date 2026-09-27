<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Sync;

/**
 * What became of one pushed record (spec 06, 3.2).
 */
enum RecordStatus: string
{
    /** The server now holds the pushed record; the client marks it clean. */
    case Accepted = 'accepted';

    /** The server's version won; the client replaces its own with it. */
    case Stale = 'stale';

    /** A schema or domain error; the server did not change. */
    case Rejected = 'rejected';
}
