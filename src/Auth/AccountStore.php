<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Auth;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\Sanctum;
use LogicException;
use SensitiveParameter;
use TamasLabs\LaravelExpenses\Database\Casts\UtcDateTime;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Sync\SyncStore;

/**
 * Every read and write of the account tables (spec 07): the users by their
 * credentials, the devices, the refresh tokens and Sanctum's access tokens.
 * The profile and the synced data are the {@see SyncStore}'s.
 *
 * Bound as a singleton; it keeps no state.
 */
final class AccountStore
{
    /**
     * The package's tables, without the prefix.
     */
    public const string DEVICES = 'devices';

    public const string REFRESH_TOKENS = 'refresh_tokens';

    /**
     * @return (Model&Authenticatable&MustVerifyEmail)|null
     */
    public function findByEmail(string $email): ?Model
    {
        $user = PackageConfig::userModel()::query()->where('email', $email)->first();

        return $user === null ? null : UserModel::of($user);
    }

    /**
     * @return (Model&Authenticatable&MustVerifyEmail)|null
     */
    public function findByUuid(string $uuid): ?Model
    {
        $user = PackageConfig::userModel()::query()->where('uuid', $uuid)->first();

        return $user === null ? null : UserModel::of($user);
    }

    /**
     * @return (Model&Authenticatable&MustVerifyEmail)|null
     */
    public function find(int|string $key): ?Model
    {
        $user = PackageConfig::userModel()::query()->whereKey($key)->first();

        return $user === null ? null : UserModel::of($user);
    }

    /**
     * @phpstan-impure It reads the table, which another request may change.
     */
    public function emailTaken(string $email): bool
    {
        return PackageConfig::userModel()::query()->where('email', $email)->exists();
    }

    /**
     * Stores a new password hash, and a new remember token with it, as
     * Laravel's own reset does.
     */
    public function changePassword(Model&Authenticatable $user, #[SensitiveParameter] string $hash): void
    {
        $user->forceFill([$user->getAuthPasswordName() => $hash]);

        if ($user->getRememberTokenName() !== '') {
            $user->setRememberToken(Str::random(60));
        }

        $user->save();
    }

    /**
     * @return bool Whether it was not verified before.
     */
    public function markEmailVerified(Model&MustVerifyEmail $user): bool
    {
        return ! $user->hasVerifiedEmail() && $user->markEmailAsVerified();
    }

    /**
     * Deletes the user's row: a hard delete, even when the host's model
     * deletes softly. Everything pointing at it goes first.
     */
    public function deleteUser(Model $user): void
    {
        $user->forceDelete();
    }

    /**
     * Creates a Sanctum access token.
     *
     * @param  list<string>  $abilities
     * @return string The plain text token, for the client.
     *
     * @throws LogicException When the model does not use Sanctum's HasApiTokens.
     */
    public function createAccessToken(Model $user, string $name, array $abilities, CarbonImmutable $expiresAt): string
    {
        if (! method_exists($user, 'createToken')) {
            throw new LogicException(sprintf('The %s model does not use Sanctum\'s HasApiTokens trait.', $user::class));
        }

        $token = $user->createToken($name, $abilities, $expiresAt);

        return $token instanceof NewAccessToken ? $token->plainTextToken : throw new LogicException('Sanctum created no access token.');
    }

    /**
     * Deletes the user's access tokens: the ones of one device (by name), or all.
     */
    public function deleteAccessTokens(int|string $userKey, ?string $name = null): void
    {
        Sanctum::personalAccessTokenModel()::query()
            ->where('tokenable_type', UserModel::make()->getMorphClass())
            ->where('tokenable_id', $userKey)
            ->when($name !== null, static fn (Builder $query): Builder => $query->where('name', $name))
            ->delete();
    }

    /**
     * The key of the user the device belongs to, locking its row until the
     * transaction ends; `null` for an unknown device.
     */
    public function lockDevice(string $deviceId): int|string|null
    {
        $row = $this->devices()->where('device_id', $deviceId)->lockForUpdate()->first(['user_id']);

        if ($row === null) {
            return null;
        }

        $owner = $row->user_id ?? null;

        return \is_int($owner) || \is_string($owner) ? $owner : throw new LogicException('A device row has no owner.');
    }

    public function insertDevice(int|string $userKey, string $deviceId, CarbonImmutable $now): void
    {
        $at = self::format($now);

        $this->devices()->insert(['device_id' => $deviceId, 'user_id' => $userKey, 'linked_at' => $at, 'last_seen_at' => $at]);
    }

    /**
     * Hands the device over to another user.
     */
    public function moveDevice(string $deviceId, int|string $userKey, CarbonImmutable $now): void
    {
        $at = self::format($now);

        $this->devices()->where('device_id', $deviceId)->update(['user_id' => $userKey, 'linked_at' => $at, 'last_seen_at' => $at]);
    }

    public function touchDevice(int|string $userKey, string $deviceId, CarbonImmutable $now): void
    {
        $this->devices()->where('user_id', $userKey)->where('device_id', $deviceId)->update(['last_seen_at' => self::format($now)]);
    }

    /**
     * Deletes the user's devices: one, or all.
     */
    public function deleteDevices(int|string $userKey, ?string $deviceId = null): void
    {
        $this->devices()->where('user_id', $userKey)
            ->when($deviceId !== null, static fn (QueryBuilder $query): QueryBuilder => $query->where('device_id', $deviceId))
            ->delete();
    }

    /**
     * @param  string  $hash  The SHA-256 of the token; the token itself is never stored.
     */
    public function insertRefreshToken(int|string $userKey, string $deviceId, string $family, string $hash, CarbonImmutable $expiresAt): void
    {
        $this->refreshTokens()->insert([
            'user_id' => $userKey,
            'device_id' => $deviceId,
            'family' => $family,
            'token_hash' => $hash,
            'expires_at' => self::format($expiresAt),
            'used_at' => null,
        ]);
    }

    /**
     * The refresh token with the hash, its row locked until the transaction ends.
     */
    public function lockRefreshToken(string $hash): ?RefreshToken
    {
        $row = $this->refreshTokens()->where('token_hash', $hash)->lockForUpdate()->first();

        return $row === null ? null : RefreshToken::fromRow($row);
    }

    public function markRefreshTokenUsed(int $id, CarbonImmutable $now): void
    {
        $this->refreshTokens()->where('id', $id)->whereNull('used_at')->update(['used_at' => self::format($now)]);
    }

    /**
     * Deletes the user's refresh tokens: the ones of one device, or all.
     */
    public function deleteRefreshTokens(int|string $userKey, ?string $deviceId = null): void
    {
        $this->refreshTokens()->where('user_id', $userKey)
            ->when($deviceId !== null, static fn (QueryBuilder $query): QueryBuilder => $query->where('device_id', $deviceId))
            ->delete();
    }

    public function deleteRefreshFamily(string $family): void
    {
        $this->refreshTokens()->where('family', $family)->delete();
    }

    private function devices(): QueryBuilder
    {
        return DB::table(PackageConfig::table(self::DEVICES));
    }

    private function refreshTokens(): QueryBuilder
    {
        return DB::table(PackageConfig::table(self::REFRESH_TOKENS));
    }

    private static function format(CarbonImmutable $instant): string
    {
        return $instant->utc()->format(UtcDateTime::FORMAT);
    }
}
