<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use TamasLabs\LaravelExpenses\HasExpensesProfile;

/**
 * The development server's user model: what a host's `App\Models\User`
 * needs for the package (README, "Felhasználói modell").
 *
 * @property int $id
 * @property string $email
 * @property string $uuid
 * @property CarbonImmutable|null $email_verified_at
 */
final class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasExpensesProfile, Notifiable;

    protected $table = 'users';

    protected $hidden = ['password', 'remember_token'];
}
