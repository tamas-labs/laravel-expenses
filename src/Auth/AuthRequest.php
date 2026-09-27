<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Auth;

use Closure;
use Illuminate\Http\Request;
use JsonException;
use LogicException;
use stdClass;
use TamasLabs\ExpensesSchema\ExpensesSchema;
use TamasLabs\LaravelExpenses\Contract\ContractIssue;
use TamasLabs\LaravelExpenses\Contract\ContractValidator;
use TamasLabs\LaravelExpenses\Support\Json;

/**
 * Reads the body of an account request: decoded with objects, normalized,
 * and checked against its protocol document (spec 07, 5), so the fields
 * follow exactly the limits the client's copy of the contract shows.
 *
 * Bound as a singleton; it keeps no state.
 */
final class AuthRequest
{
    public function __construct(private readonly ContractValidator $validator) {}

    /**
     * @param  string  $document  The protocol document, e.g. `login-request`.
     * @param  array<string, Closure(string): string>  $normalize  Field → how its string value is normalized before the check.
     *
     * @throws AuthError When the body is not JSON or does not match the document (validation_failed).
     */
    public function body(Request $request, string $document, array $normalize = []): stdClass
    {
        try {
            $body = Json::decode($request->getContent());
        } catch (JsonException $exception) {
            throw AuthError::validationFailed($document, [new ContractIssue('/', 'json', $exception->getMessage())]);
        }

        if ($body instanceof stdClass) {
            foreach ($normalize as $field => $normalizer) {
                if (\is_string($body->{$field} ?? null)) {
                    $body->{$field} = $normalizer($body->{$field});
                }
            }
        }

        $result = $this->validator->validateAgainst(ExpensesSchema::protocolId($document), $body);

        if (! $result->valid || ! $body instanceof stdClass) {
            throw AuthError::validationFailed($document, $result->issues);
        }

        return $body;
    }

    /**
     * An email address as the account stores it: trimmed and lower-cased.
     */
    public static function email(string $email): string
    {
        return mb_strtolower(self::trim($email));
    }

    /**
     * Whitespace cut from both ends, Unicode spaces included (as JavaScript's
     * `trim()` does on the client).
     */
    public static function trim(string $value): string
    {
        return preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $value) ?? $value;
    }

    /**
     * A string field of a validated body.
     *
     * @throws LogicException When the document did not make it a string.
     */
    public static function string(stdClass $body, string $field): string
    {
        $value = $body->{$field} ?? null;

        return \is_string($value) ? $value : throw new LogicException(sprintf('The validated body has no string "%s".', $field));
    }

    /**
     * A nullable string field of a validated body.
     *
     * @throws LogicException When the document did not make it a string or null.
     */
    public static function nullableString(stdClass $body, string $field): ?string
    {
        $value = $body->{$field} ?? null;

        return $value === null || \is_string($value) ? $value : throw new LogicException(sprintf('The validated body has no string "%s".', $field));
    }
}
