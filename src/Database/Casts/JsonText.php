<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Database\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;
use stdClass;
use TamasLabs\LaravelExpenses\Support\Json;
use UnexpectedValueException;

/**
 * A JSON text column holding a decoded tree: `stdClass` objects and lists.
 *
 * Unlike Laravel's `array` / `json` casts, it tells `{}` from `[]` and keeps
 * the key order, as both go through {@see Json}. An associative PHP array is
 * refused, because an empty one would come back as `[]`.
 *
 * @implements CastsAttributes<stdClass|list<mixed>|null, mixed>
 */
final class JsonText implements CastsAttributes
{
    /**
     * A fresh tree on every read: a cached `stdClass` could be changed in
     * place and written back without the model noticing.
     */
    public bool $withoutObjectCaching = true;

    /**
     * @param  array<string, mixed>  $attributes
     * @return stdClass|list<mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): stdClass|array|null
    {
        if ($value === null) {
            return null;
        }

        try {
            $tree = \is_string($value) ? Json::decode($value) : null;
        } catch (JsonException $exception) {
            throw new UnexpectedValueException(sprintf('The %s column does not hold valid JSON: %s', $key, $exception->getMessage()), 0, $exception);
        }

        if (! $tree instanceof stdClass && ! (\is_array($tree) && array_is_list($tree))) {
            throw new UnexpectedValueException(sprintf('The %s column must hold a JSON object or array; got %s.', $key, get_debug_type($tree)));
        }

        return $tree;
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidArgumentException When the value is not a `stdClass`, a list or null.
     * @throws JsonException When the tree cannot be encoded.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof stdClass && ! (\is_array($value) && array_is_list($value))) {
            throw new InvalidArgumentException(sprintf(
                'The %s attribute takes a stdClass, a list or null (objects decoded as stdClass); got %s.',
                $key,
                \is_array($value) ? 'an associative array' : get_debug_type($value),
            ));
        }

        return Json::encode($value);
    }
}
