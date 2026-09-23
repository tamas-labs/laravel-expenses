<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Support;

use JsonException;

/**
 * The one place JSON is encoded and decoded, so what the package validates is
 * byte-for-byte what it sends.
 *
 * Decoding always yields objects: in JSON `{}` and `[]` differ, in a PHP array
 * they do not, and the contract tells them apart (`customFields: {}`).
 */
final class Json
{
    public const int ENCODE_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * Nesting limit for decoding. The deepest contract document (a push
     * request carrying expenses with items) is well under this.
     */
    public const int MAX_DEPTH = 64;

    /**
     * @throws JsonException
     */
    public static function encode(mixed $value): string
    {
        return json_encode($value, self::ENCODE_FLAGS);
    }

    /**
     * Decodes JSON objects as `stdClass`, never as associative arrays.
     *
     * @throws JsonException
     */
    public static function decode(string $json): mixed
    {
        return json_decode($json, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
    }
}
