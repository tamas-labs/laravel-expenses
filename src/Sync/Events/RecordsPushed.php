<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Sync\Events;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A push wrote records; dispatched after its transaction committed, and only
 * when it wrote anything (spec 06, 5.6).
 *
 * For the host application (statistics, notifications); the package itself
 * does not listen to it.
 */
final readonly class RecordsPushed
{
    /**
     * @param  array<string, int>  $counts  Resource → the records written, in the push order.
     */
    public function __construct(
        public Authenticatable $user,
        public array $counts,
    ) {}
}
