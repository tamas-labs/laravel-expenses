<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Auth;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use TamasLabs\LaravelExpenses\Contract\ContractIssue;
use TamasLabs\LaravelExpenses\Support\Json;

/**
 * An account or token request the server refuses (spec 07), rendered as the
 * `protocol/error` envelope with the contract's error code.
 *
 * A client error, so it is never reported to the log.
 */
final class AuthError extends RuntimeException implements ShouldntReport
{
    /**
     * @param  list<ContractIssue>  $issues
     * @param  array<string, string>  $headers
     */
    private function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly array $issues = [],
        public readonly ?string $resource = null,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    /**
     * The fields of an account request break its document or the server's
     * rules (the password's length, the currency).
     *
     * @param  string  $document  The request document, e.g. `register-request`.
     * @param  list<ContractIssue>  $issues
     */
    public static function validationFailed(string $document, array $issues): self
    {
        return new self('validation_failed', 422, sprintf('The request does not pass the %s rules.', $document), $issues, $document);
    }

    /**
     * @param  list<ContractIssue>  $issues  One per field the request would change.
     */
    public static function fieldNotWritable(array $issues): self
    {
        return new self('field_not_writable', 422, 'Only displayName, defaultCurrencyId and updatedAt can be changed.', $issues, 'user');
    }

    public static function emailTaken(): self
    {
        return new self('email_taken', 422, 'An account with this email address already exists.');
    }

    /**
     * The one answer to every failed sign-in, so it tells nothing of which
     * part was wrong or whether the account exists.
     */
    public static function invalidCredentials(): self
    {
        return new self('invalid_credentials', 401, 'The email address or the password is wrong.');
    }

    public static function unauthenticated(): self
    {
        return new self('unauthenticated', 401, 'The access token is missing, expired or invalid.');
    }

    public static function forbidden(): self
    {
        return new self('forbidden', 403, 'The access token does not grant access to this endpoint.');
    }

    public static function refreshTokenInvalid(): self
    {
        return new self('refresh_token_invalid', 401, 'The refresh token is expired, unknown or belongs to another device.');
    }

    public static function refreshTokenReused(): self
    {
        return new self('refresh_token_reused', 401, 'The refresh token was already used; every token of the device is revoked.');
    }

    public static function emailNotVerified(): self
    {
        return new self('email_not_verified', 403, 'The email address has to be verified first.');
    }

    public static function resetTokenInvalid(): self
    {
        return new self('reset_token_invalid', 422, 'The password reset token is expired or invalid.');
    }

    public static function tooManyAttempts(int $retryAfter): self
    {
        $seconds = max(1, $retryAfter);

        return new self('too_many_attempts', 429, sprintf('Too many attempts; try again in %d seconds.', $seconds), headers: ['Retry-After' => (string) $seconds]);
    }

    /**
     * The `protocol/error` envelope.
     */
    public function render(): JsonResponse
    {
        $error = ['code' => $this->errorCode, 'message' => $this->getMessage()];

        if ($this->resource !== null) {
            $error['resource'] = $this->resource;
        }

        if ($this->issues !== []) {
            $error['issues'] = array_map(static fn (ContractIssue $issue): array => $issue->toArray(), $this->issues);
        }

        return new JsonResponse(['error' => $error], $this->status, $this->headers, Json::ENCODE_FLAGS);
    }
}
