<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Auth;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use TamasLabs\LaravelExpenses\Database\Casts\UtcDateTime;
use TamasLabs\LaravelExpenses\Support\PackageConfig;

/**
 * A row of the `refresh_tokens` table, for Laravel's `model:prune` (spec 08,
 * 3.4): a token goes a week after it expired. Until then a used one stays,
 * so presenting it again still reveals a theft.
 *
 * Only the pruning reads the table through this model; the token flows go
 * through the {@see AccountStore}.
 *
 * @property int $id
 * @property CarbonImmutable $expires_at
 */
final class RefreshToken extends Model
{
    use MassPrunable;

    /**
     * How long an expired token is kept, in days.
     */
    public const int GRACE_DAYS = 7;

    public $timestamps = false;

    public function getTable(): string
    {
        return PackageConfig::table(AccountStore::REFRESH_TOKENS);
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->where('expires_at', '<', CarbonImmutable::now('UTC')->subDays(self::GRACE_DAYS)->format(UtcDateTime::FORMAT));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => UtcDateTime::class,
            'used_at' => UtcDateTime::class,
        ];
    }
}
