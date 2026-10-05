<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Http;

use Illuminate\Http\JsonResponse;
use TamasLabs\LaravelExpenses\Support\Json;

/**
 * The `protocol/error` envelopes of the HTTP layer's own refusals (spec 08):
 * the rate limit, the body size and the unexpected failure.
 */
final class HttpError
{
    /**
     * @param  array<array-key, mixed>  $headers  The limiter's headers, `Retry-After` among them.
     */
    public static function tooManyRequests(array $headers): JsonResponse
    {
        $strings = [];

        foreach ($headers as $name => $value) {
            if (\is_scalar($value)) {
                $strings[(string) $name] = (string) $value;
            }
        }

        return self::envelope(['code' => 'too_many_requests', 'message' => 'Too many requests; wait for Retry-After seconds before the next one.'], 429, $strings);
    }

    public static function payloadTooLarge(int $maxBytes): JsonResponse
    {
        return self::envelope(['code' => 'payload_too_large', 'message' => sprintf('The request body is larger than %d KB.', intdiv($maxBytes, 1024))], 413);
    }

    /**
     * An unexpected failure: no detail of it, only the id the server's log
     * knows it by.
     */
    public static function serverError(string $requestId): JsonResponse
    {
        return self::envelope(['code' => 'server_error', 'message' => 'Something went wrong on the server.', 'requestId' => $requestId], 500);
    }

    /**
     * @param  array<string, string>  $error
     * @param  array<string, string>  $headers
     */
    private static function envelope(array $error, int $status, array $headers = []): JsonResponse
    {
        return new JsonResponse(['error' => $error], $status, $headers, Json::ENCODE_FLAGS);
    }
}
