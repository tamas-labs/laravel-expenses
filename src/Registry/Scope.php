<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Registry;

/**
 * Whose rows a resource's table holds.
 */
enum Scope: string
{
    /** Rows of one user, keyed `(user_id, id)`. */
    case Owned = 'owned';

    /** One list shared by every user (the currencies). */
    case Global = 'global';

    /** The user's own account row, on the host's users table. */
    case Account = 'account';
}
