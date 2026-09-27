<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Rules;

use LogicException;

/**
 * Typed reads from a record the contract has accepted.
 *
 * A record of another shape is a programming error (`LogicException`), as in
 * the mappers: the rules run after the schema.
 *
 * @internal
 */
final class RecordFields
{
    /**
     * 2^63: the first float past PHP_INT_MAX (and -2^63 is PHP_INT_MIN).
     */
    private const float INT_LIMIT = 9223372036854775808.0;

    /**
     * @throws LogicException When the record has no such field.
     */
    public static function get(object $record, string $field): mixed
    {
        $values = get_object_vars($record);

        if (! \array_key_exists($field, $values)) {
            throw new LogicException(sprintf('A record without "%s" reached the domain rules: validate it first.', $field));
        }

        return $values[$field];
    }

    /**
     * @throws LogicException When the field is missing or not a string.
     */
    public static function string(object $record, string $field): string
    {
        $value = self::get($record, $field);

        return \is_string($value) ? $value : self::unexpected($field, $value);
    }

    /**
     * @throws LogicException When the field is missing or neither a string nor null.
     */
    public static function nullableString(object $record, string $field): ?string
    {
        $value = self::get($record, $field);

        return $value === null || \is_string($value) ? $value : self::unexpected($field, $value);
    }

    /**
     * A JSON integer, which may arrive as `1.0`; a float only beyond the range
     * of a PHP integer.
     *
     * @throws LogicException When the field is missing or not a whole number.
     */
    public static function integer(object $record, string $field): int|float
    {
        $value = self::get($record, $field);

        if (\is_int($value)) {
            return $value;
        }

        if (! \is_float($value) || floor($value) !== $value) {
            self::unexpected($field, $value);
        }

        return $value >= -self::INT_LIMIT && $value < self::INT_LIMIT ? (int) $value : $value;
    }

    public static function id(object $record): string
    {
        return self::string($record, 'id');
    }

    public static function isTombstone(object $record): bool
    {
        return self::get($record, 'deletedAt') !== null;
    }

    /**
     * @throws LogicException Always.
     */
    private static function unexpected(string $field, mixed $value): never
    {
        throw new LogicException(sprintf('The domain rules cannot read %s as "%s": validate the record first.', get_debug_type($value), $field));
    }
}
