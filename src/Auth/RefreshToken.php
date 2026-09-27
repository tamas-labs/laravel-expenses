<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Auth;

use Carbon\CarbonImmutable;
use LogicException;
use TamasLabs\LaravelExpenses\Database\Casts\UtcDateTime;

/**
 * A stored refresh token (spec 07, 4.2), as read from its row.
 *
 * @internal
 */
final readonly class RefreshToken
{
    public function __construct(
        public int $id,
        public int|string $userKey,
        public string $deviceId,
        public string $family,
        public CarbonImmutable $expiresAt,
        public ?CarbonImmutable $usedAt,
    ) {}

    /**
     * @throws LogicException When the row does not hold a refresh token.
     */
    public static function fromRow(object $row): self
    {
        $id = $row->id ?? null;
        $userKey = $row->user_id ?? null;
        $deviceId = $row->device_id ?? null;
        $family = $row->family ?? null;
        $expiresAt = $row->expires_at ?? null;
        $usedAt = $row->used_at ?? null;

        if (! \is_int($id) || ! (\is_int($userKey) || \is_string($userKey)) || ! \is_string($deviceId) || ! \is_string($family)
            || ! \is_string($expiresAt) || ! ($usedAt === null || \is_string($usedAt))) {
            throw new LogicException('A refresh_tokens row does not have the expected columns.');
        }

        return new self($id, $userKey, $deviceId, $family, self::instant($expiresAt), $usedAt === null ? null : self::instant($usedAt));
    }

    private static function instant(string $value): CarbonImmutable
    {
        $instant = CarbonImmutable::rawCreateFromFormat('!'.UtcDateTime::FORMAT, $value, 'UTC');

        return $instant instanceof CarbonImmutable ? $instant : throw new LogicException(sprintf('"%s" is not a stored timestamp.', $value));
    }
}
