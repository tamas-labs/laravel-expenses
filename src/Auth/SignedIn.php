<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;

/**
 * A registration or a sign-in: the account and the device's new tokens.
 */
final readonly class SignedIn
{
    public function __construct(
        public Model&Authenticatable&MustVerifyEmail $user,
        public TokenPair $tokens,
    ) {}
}
