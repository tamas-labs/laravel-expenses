<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use TamasLabs\LaravelExpenses\Database\Casts\UtcDateTime;
use TamasLabs\LaravelExpenses\Models\Currency;

/**
 * The contract's `user` record on the host's user model.
 *
 * `uuid` is the contract `id` (the table's own key never leaves the server);
 * `profile_created_at` / `profile_updated_at` are the client's createdAt /
 * updatedAt, apart from Laravel's own timestamps.
 *
 * @phpstan-require-extends Model
 */
trait HasExpensesProfile
{
    public static function bootHasExpensesProfile(): void
    {
        static::creating(static function (Model $user): void {
            if (blank($user->getAttribute('uuid'))) {
                $user->setAttribute('uuid', Str::uuid()->toString());
            }
        });
    }

    public function initializeHasExpensesProfile(): void
    {
        $this->mergeCasts([
            'registered_at' => UtcDateTime::class,
            'profile_created_at' => UtcDateTime::class,
            'profile_updated_at' => UtcDateTime::class,
            'server_seq' => 'integer',
        ]);
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function defaultCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'default_currency_id');
    }
}
