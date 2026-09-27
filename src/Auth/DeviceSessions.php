<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Auth;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use LogicException;
use SensitiveParameter;
use TamasLabs\LaravelExpenses\Support\PackageConfig;

/**
 * The devices signed in and their tokens (spec 07, 4.1 and 4.2).
 *
 * A device belongs to one account at a time and holds one sign-in: a family
 * of refresh tokens, each used once, and the Sanctum access tokens named
 * after the device. Bound as a singleton; it keeps no state.
 */
final class DeviceSessions
{
    /**
     * The ability an access token needs on the protected routes.
     */
    public const string ABILITY = 'expenses:access';

    /**
     * The name of a device's access tokens: `device:<deviceId>`.
     */
    public const string TOKEN_NAME_PREFIX = 'device:';

    /**
     * Deadlocks and lock wait timeouts retry the refresh.
     */
    private const int ATTEMPTS = 3;

    public function __construct(private readonly AccountStore $accounts) {}

    /**
     * Signs the device in to the user's account with a new token family.
     *
     * The device is bound to the user; one bound to another account moves
     * over, and the tokens it held there are revoked. So are its earlier
     * tokens on this account: a device holds one sign-in. Runs inside the
     * caller's transaction.
     */
    public function signIn(Model&Authenticatable $user, string $deviceId): TokenPair
    {
        $now = CarbonImmutable::now('UTC');
        $key = self::key($user);
        $owner = $this->accounts->lockDevice($deviceId);

        if ($owner === null) {
            $this->accounts->insertDevice($key, $deviceId, $now);
        } else {
            $this->revokeTokens($owner, $deviceId);
            $this->accounts->moveDevice($deviceId, $key, $now);
        }

        return $this->issue($user, $deviceId, Str::uuid()->toString(), $now);
    }

    /**
     * Rotates a refresh token: uses it up and hands out a new pair in its
     * family.
     *
     * A token presented a second time means one of two holders has an old
     * copy, so the family and the device's access tokens are revoked.
     *
     * @throws AuthError When the token is invalid, expired, another device's or used.
     */
    public function rotate(#[SensitiveParameter] string $refreshToken, string $deviceId): TokenPair
    {
        $outcome = DB::transaction(function () use ($refreshToken, $deviceId): TokenPair|AuthError {
            $now = CarbonImmutable::now('UTC');
            $stored = $this->accounts->lockRefreshToken(self::hash($refreshToken));

            if ($stored === null || $stored->expiresAt->lessThanOrEqualTo($now)) {
                return AuthError::refreshTokenInvalid();
            }

            if ($stored->usedAt !== null) {
                $this->accounts->deleteRefreshFamily($stored->family);
                $this->accounts->deleteAccessTokens($stored->userKey, self::tokenName($stored->deviceId));

                return AuthError::refreshTokenReused();
            }

            $user = $stored->deviceId === $deviceId ? $this->accounts->find($stored->userKey) : null;

            if ($user === null) {
                return AuthError::refreshTokenInvalid();
            }

            $this->accounts->markRefreshTokenUsed($stored->id, $now);
            $this->accounts->touchDevice($stored->userKey, $deviceId, $now);

            return $this->issue($user, $deviceId, $stored->family, $now);
        }, self::ATTEMPTS);

        // Thrown after the commit: the revocation of a reused family stays.
        if ($outcome instanceof AuthError) {
            throw $outcome;
        }

        return $outcome;
    }

    /**
     * Signs the device out: revokes its tokens and unbinds it. Runs inside
     * the caller's transaction.
     */
    public function signOut(Authenticatable $user, string $deviceId): void
    {
        $key = self::key($user);

        $this->revokeTokens($key, $deviceId);
        $this->accounts->deleteDevices($key, $deviceId);
    }

    /**
     * Revokes every token of the user and unbinds every device. Runs inside
     * the caller's transaction.
     */
    public function revokeAll(Authenticatable $user): void
    {
        $key = self::key($user);

        $this->accounts->deleteAccessTokens($key);
        $this->accounts->deleteRefreshTokens($key);
        $this->accounts->deleteDevices($key);
    }

    /**
     * Records that the device of the request's access token was seen.
     */
    public function markSeen(Authenticatable $user): void
    {
        $deviceId = self::deviceOf($user);

        if ($deviceId !== null) {
            $this->accounts->touchDevice(self::key($user), $deviceId, CarbonImmutable::now('UTC'));
        }
    }

    /**
     * The device of the access token the user authenticated with; `null`
     * without a device token.
     */
    public static function deviceOf(Authenticatable $user): ?string
    {
        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
        $name = $token instanceof PersonalAccessToken ? $token->getAttribute('name') : null;

        return \is_string($name) && str_starts_with($name, self::TOKEN_NAME_PREFIX) ? substr($name, \strlen(self::TOKEN_NAME_PREFIX)) : null;
    }

    private function issue(Model&Authenticatable $user, string $deviceId, string $family, CarbonImmutable $now): TokenPair
    {
        // Whole seconds: Sanctum's expires_at column holds no fraction.
        $accessExpiresAt = $now->startOfSecond()->addMinutes(PackageConfig::accessTtl());
        $refreshExpiresAt = $now->addMinutes(PackageConfig::refreshTtl());
        $refreshToken = self::newRefreshToken();

        $accessToken = $this->accounts->createAccessToken($user, self::tokenName($deviceId), [self::ABILITY], $accessExpiresAt);
        $this->accounts->insertRefreshToken(self::key($user), $deviceId, $family, self::hash($refreshToken), $refreshExpiresAt);

        return new TokenPair($accessToken, $accessExpiresAt, $refreshToken, $refreshExpiresAt);
    }

    private function revokeTokens(int|string $userKey, string $deviceId): void
    {
        $this->accounts->deleteAccessTokens($userKey, self::tokenName($deviceId));
        $this->accounts->deleteRefreshTokens($userKey, $deviceId);
    }

    /**
     * 64 random bytes, base64url without padding.
     */
    private static function newRefreshToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }

    private static function hash(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    private static function tokenName(string $deviceId): string
    {
        return self::TOKEN_NAME_PREFIX.$deviceId;
    }

    /**
     * @throws LogicException When the user has no usable key.
     */
    private static function key(Authenticatable $user): int|string
    {
        $key = $user->getAuthIdentifier();

        return \is_int($key) || \is_string($key) ? $key : throw new LogicException('The user has no key.');
    }
}
