<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Database\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * A `datetime(3)` column holding a UTC instant, whatever the app's time zone.
 *
 * Laravel's `datetime` cast reads the column in the app's time zone and writes
 * whole seconds only; this one reads the stored string as UTC and writes the
 * instant converted to UTC, to the millisecond (truncated, not rounded).
 *
 * @implements CastsAttributes<CarbonImmutable|null, mixed>
 */
final class UtcDateTime implements CastsAttributes
{
    /**
     * How MySQL returns a `datetime(3)` column, and how this cast writes it.
     */
    public const string FORMAT = 'Y-m-d H:i:s.v';

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        $instant = \is_string($value) ? self::parse($value) : null;

        if ($instant === null) {
            throw new UnexpectedValueException(sprintf(
                'The %s column must hold a "%s" string; got %s.',
                $key,
                self::FORMAT,
                get_debug_type($value),
            ));
        }

        return $instant;
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidArgumentException When the value is neither a date nor a string in the column's format.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc()->format(self::FORMAT);
        }

        if (\is_string($value) && self::parse($value) !== null) {
            return $value;
        }

        throw new InvalidArgumentException(sprintf(
            'The %s attribute takes a DateTimeInterface or a UTC "%s" string; got %s.',
            $key,
            self::FORMAT,
            \is_string($value) ? '"'.$value.'"' : get_debug_type($value),
        ));
    }

    private static function parse(string $value): ?CarbonImmutable
    {
        $instant = CarbonImmutable::rawCreateFromFormat('!'.self::FORMAT, $value, 'UTC');

        return $instant instanceof CarbonImmutable && $instant->format(self::FORMAT) === $value ? $instant : null;
    }
}
