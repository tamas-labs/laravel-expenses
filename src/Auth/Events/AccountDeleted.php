<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Auth\Events;

/**
 * An account and all its data were deleted; dispatched after the
 * transaction committed (spec 07, 5.6).
 *
 * The user row is gone by then, so only the contract id travels. For the
 * host application; the package itself does not listen to it.
 */
final readonly class AccountDeleted
{
    public function __construct(
        public string $userUuid,
    ) {}
}
