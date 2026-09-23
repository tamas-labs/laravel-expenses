<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Database\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * A `double` column that stores exactly the float it is given.
 *
 * PDO binds a PHP float as a string made with the `precision` ini setting
 * (14 digits by default), so `1/3` would be stored as `0.33333333333333`.
 * This cast writes 17 significant digits, which always parse back to the same
 * double, with `%h`: `%g` would use the locale's decimal separator.
 *
 * @implements CastsAttributes<float|null, mixed>
 */
final class Double implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?float
    {
        if ($value === null) {
            return null;
        }

        if (\is_float($value) || \is_int($value) || (\is_string($value) && is_numeric($value))) {
            return (float) $value;
        }

        throw new UnexpectedValueException(sprintf('The %s column must hold a number; got %s.', $key, get_debug_type($value)));
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidArgumentException When the value is not a finite number.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! (\is_float($value) || \is_int($value)) || ! is_finite((float) $value)) {
            throw new InvalidArgumentException(sprintf(
                'The %s attribute takes a finite number; got %s.',
                $key,
                get_debug_type($value),
            ));
        }

        return sprintf('%.17h', $value);
    }
}
