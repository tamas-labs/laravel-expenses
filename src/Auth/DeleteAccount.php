<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use TamasLabs\LaravelExpenses\Auth\Events\AccountDeleted;
use TamasLabs\LaravelExpenses\Sync\SyncStore;

/**
 * Deletes an account with all its data (spec 07, 5.6).
 *
 * Public API: `DELETE /me` calls it after checking the password, and so may
 * the host's web page for account deletion (a Google Play requirement),
 * after checking the user's identity its own way.
 */
final class DeleteAccount
{
    /**
     * Deadlocks and lock wait timeouts retry the whole transaction.
     */
    public const int ATTEMPTS = 3;

    public function __construct(
        private readonly SyncStore $store,
        private readonly DeviceSessions $sessions,
        private readonly AccountStore $accounts,
    ) {}

    /**
     * In one transaction, under the user's lock: the synced data against
     * the push order, then the devices and every token, then the user row.
     * {@see AccountDeleted} follows the commit.
     *
     * @throws LogicException When the user has no contract id, or no row.
     */
    public function __invoke(Model&Authenticatable $user): void
    {
        $uuid = $user->getAttribute('uuid');

        if (! \is_string($uuid)) {
            throw new LogicException('The user has no uuid.');
        }

        DB::transaction(function () use ($user, $uuid): void {
            $this->store->lockUser($user);
            $this->store->deleteOwnedData($user);
            $this->sessions->revokeAll($user);
            $this->accounts->deleteUser($user);

            DB::afterCommit(static fn () => Event::dispatch(new AccountDeleted($uuid)));
        }, self::ATTEMPTS);
    }
}
