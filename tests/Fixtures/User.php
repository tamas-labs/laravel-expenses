<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Tests\Fixtures;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use TamasLabs\LaravelExpenses\HasExpensesProfile;

/**
 * The host application's user model, as the tests configure it
 * (`expenses.user_model`), on Testbench's stock users table.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $uuid
 * @property string|null $default_currency_id
 * @property CarbonImmutable|null $registered_at
 * @property CarbonImmutable|null $profile_created_at
 * @property CarbonImmutable|null $profile_updated_at
 * @property int|null $server_seq
 */
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasExpensesProfile, HasFactory;

    protected $table = 'users';

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
