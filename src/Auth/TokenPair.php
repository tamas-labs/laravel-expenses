<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Auth;

use Carbon\CarbonImmutable;
use JsonSerializable;
use SensitiveParameter;
use TamasLabs\LaravelExpenses\Mapping\RecordMapper;

/**
 * A device's access and refresh token: the contract's `tokens` object
 * (`protocol/common#/$defs/tokens`).
 */
final readonly class TokenPair implements JsonSerializable
{
    public function __construct(
        #[SensitiveParameter] public string $accessToken,
        public CarbonImmutable $accessTokenExpiresAt,
        #[SensitiveParameter] public string $refreshToken,
        public CarbonImmutable $refreshTokenExpiresAt,
    ) {}

    /**
     * @return array{accessToken: string, accessTokenExpiresAt: string, refreshToken: string, refreshTokenExpiresAt: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'accessToken' => $this->accessToken,
            'accessTokenExpiresAt' => $this->accessTokenExpiresAt->utc()->format(RecordMapper::TIMESTAMP_FORMAT),
            'refreshToken' => $this->refreshToken,
            'refreshTokenExpiresAt' => $this->refreshTokenExpiresAt->utc()->format(RecordMapper::TIMESTAMP_FORMAT),
        ];
    }
}
