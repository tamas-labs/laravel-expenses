<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Mapping\Mappers;

use TamasLabs\LaravelExpenses\HasExpensesProfile;
use TamasLabs\LaravelExpenses\Mapping\Field;
use TamasLabs\LaravelExpenses\Mapping\RecordMapper;

/**
 * The contract's `user` record on the host's user model (see
 * {@see HasExpensesProfile}): `id` is the `uuid`
 * column, `displayName` is `name`, and the record's createdAt / updatedAt are
 * the profile timestamps, not Laravel's own.
 *
 * Which of these a client may change is the account endpoints' decision.
 */
final class UserMapper extends RecordMapper
{
    /**
     * @return list<Field>
     */
    protected function fields(): array
    {
        return [
            Field::uuid('id', 'uuid'),
            Field::text('email', 'email'),
            Field::text('displayName', 'name'),
            Field::uuid('defaultCurrencyId', 'default_currency_id')->nullable(),
            Field::timestamp('registeredAt', 'registered_at'),
            Field::timestamp('createdAt', 'profile_created_at'),
            Field::timestamp('updatedAt', 'profile_updated_at'),
        ];
    }
}
