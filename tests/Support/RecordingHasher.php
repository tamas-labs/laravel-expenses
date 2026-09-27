<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Tests\Support;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Hashing\HashManager;
use SensitiveParameter;

/**
 * The app's hasher, noting every hash a password is checked against.
 */
final class RecordingHasher implements Hasher
{
    /**
     * @var list<string>
     */
    public array $checked = [];

    public function __construct(private readonly HashManager $hasher) {}

    /**
     * @return array<array-key, mixed>
     */
    public function info(mixed $hashedValue): array
    {
        return $this->hasher->info(self::string($hashedValue));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function make(#[SensitiveParameter] mixed $value, array $options = []): string
    {
        return $this->hasher->make(self::string($value), $options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function check(#[SensitiveParameter] mixed $value, mixed $hashedValue, array $options = []): bool
    {
        $this->checked[] = self::string($hashedValue);

        return $this->hasher->check(self::string($value), $hashedValue, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function needsRehash(mixed $hashedValue, array $options = []): bool
    {
        return $this->hasher->needsRehash(self::string($hashedValue), $options);
    }

    public function getDefaultDriver(): string
    {
        return $this->hasher->getDefaultDriver();
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
