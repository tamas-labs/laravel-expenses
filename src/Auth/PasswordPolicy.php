<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Auth;

use Illuminate\Support\Facades\Hash;
use SensitiveParameter;
use TamasLabs\LaravelExpenses\Contract\ContractIssue;

/**
 * The upper limit of a new password (spec 07, 4.4); the lower one, 8
 * characters, is the contract's (`newPassword`).
 *
 * bcrypt reads only the first 72 bytes and drops the rest without a word, so
 * two passwords sharing those bytes would be the same. The Argon2 hashers
 * have no such cut; 256 characters bounds the work.
 */
final class PasswordPolicy
{
    public const int BCRYPT_MAX_BYTES = 72;

    public const int MAX_CHARACTERS = 256;

    /**
     * @return list<ContractIssue> Empty when the password fits.
     */
    public static function issues(#[SensitiveParameter] string $password, string $path = '/password'): array
    {
        if (Hash::getDefaultDriver() === 'bcrypt') {
            return \strlen($password) > self::BCRYPT_MAX_BYTES
                ? [new ContractIssue($path, 'maxLength', sprintf('must NOT be longer than %d bytes', self::BCRYPT_MAX_BYTES))]
                : [];
        }

        return mb_strlen($password) > self::MAX_CHARACTERS
            ? [new ContractIssue($path, 'maxLength', sprintf('must NOT have more than %d characters', self::MAX_CHARACTERS))]
            : [];
    }
}
