<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Mapping;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use stdClass;
use TamasLabs\LaravelExpenses\Contract\ContractIssue;

/**
 * Maps one resource between its contract record and its model, driven by the
 * resource's field list.
 *
 * The mapper takes records the contract has already accepted (spec 02): an
 * input of another shape is a programming error (`LogicException`), not a
 * rejection. It never touches the database, nor the owner and bookkeeping
 * columns (`user_id`, `server_seq`, `synced_at`), which the sync fills in.
 */
abstract class RecordMapper
{
    /**
     * The wire form of a timestamp: what the client's `Date.toISOString()` writes.
     */
    public const string TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.v\Z';

    /**
     * RFC 3339 `date-time`, as the schema's `format` accepts it.
     */
    private const string DATE_TIME_PATTERN = '/\A(\d{4}-\d{2}-\d{2})[Tt](\d{2}:\d{2}):(\d{2})(?:\.(\d+))?(?:[Zz]|([+-]\d{2}:\d{2}))\z/';

    /**
     * 2^63: the first float past PHP_INT_MAX (and -2^63 is PHP_INT_MIN).
     */
    private const float INT_LIMIT = 9223372036854775808.0;

    /**
     * @var list<Field>|null
     */
    private ?array $fields = null;

    /**
     * The resource's fields, in the order of the schema's `properties`.
     *
     * @return list<Field>
     */
    abstract protected function fields(): array;

    /**
     * A validated record, decoded with objects, as model attributes (and the
     * pivot links, if the resource has any).
     *
     * @throws MappingRejection When the record holds a value the server cannot store.
     * @throws LogicException When the record does not have the contract's shape.
     */
    public function toAttributes(object $record): MappedRecord
    {
        $values = get_object_vars($record);
        $unknown = array_diff(array_keys($values), array_map(static fn (Field $field): string => $field->name, $this->describe()));

        if ($unknown !== []) {
            throw new LogicException(sprintf('%s got a record with the unknown field(s) %s: validate it first.', static::class, implode(', ', $unknown)));
        }

        $attributes = [];
        $links = [];

        foreach ($this->describe() as $field) {
            if (! \array_key_exists($field->name, $values)) {
                throw new LogicException(sprintf('%s got a record without "%s": validate it first.', static::class, $field->name));
            }

            if ($field->type === FieldType::Links) {
                $links[$field->name] = $this->uuidList($field, $values[$field->name]);
            } else {
                $attributes[$field->column] = $this->attribute($field, $values[$field->name]);
            }
        }

        return new MappedRecord($attributes, $links);
    }

    /**
     * The model as a contract record, keys in the schema's order.
     *
     * JSON fields come out as `stdClass` and lists, never as associative
     * arrays, so `{}` stays `{}` when encoded.
     *
     * @return array<string, mixed>
     *
     * @throws LogicException When an attribute does not hold what the contract needs.
     */
    public function toPayload(Model $model): array
    {
        $payload = [];

        foreach ($this->describe() as $field) {
            $payload[$field->name] = $field->type === FieldType::Links
                ? $this->payloadLinks($model, $field)
                : $this->payloadValue($field, $model->getAttribute($field->column));
        }

        return $payload;
    }

    /**
     * The field list, for the tests and the sync.
     *
     * @return list<Field>
     */
    public function describe(): array
    {
        return $this->fields ??= $this->fields();
    }

    /**
     * The loaded value of a {@see FieldType::Links} field; `null` when the
     * storage layer has not loaded it.
     *
     * @return list<string>|null
     *
     * @throws LogicException When the mapper declares no source for the field.
     */
    protected function links(Model $model, Field $field): ?array
    {
        throw new LogicException(sprintf('%s does not say where "%s" is loaded from.', static::class, $field->name));
    }

    /**
     * @throws MappingRejection
     */
    private function attribute(Field $field, mixed $value): mixed
    {
        if ($value === null) {
            return $field->nullable ? null : $this->unexpected($field, $value);
        }

        return match ($field->type) {
            FieldType::Uuid, FieldType::Text, FieldType::Date => \is_string($value) ? $value : $this->unexpected($field, $value),
            FieldType::Int => $this->integer($field, $value),
            FieldType::Float => \is_int($value) || \is_float($value) ? (float) $value : $this->unexpected($field, $value),
            FieldType::Bool => \is_bool($value) ? $value : $this->unexpected($field, $value),
            FieldType::Timestamp => \is_string($value) ? $this->instant($field, $value) : $this->unexpected($field, $value),
            FieldType::Json => \is_array($value) || $value instanceof stdClass ? $value : $this->unexpected($field, $value),
            FieldType::Links => $this->uuidList($field, $value),
        };
    }

    private function payloadValue(Field $field, mixed $value): mixed
    {
        if ($value === null) {
            return $field->nullable ? null : $this->unloadable($field, $value);
        }

        return match ($field->type) {
            FieldType::Uuid, FieldType::Text, FieldType::Date => \is_string($value) ? $value : $this->unloadable($field, $value),
            FieldType::Int => \is_int($value) ? $value : $this->unloadable($field, $value),
            FieldType::Float => \is_int($value) || \is_float($value) ? (float) $value : $this->unloadable($field, $value),
            FieldType::Bool => \is_bool($value) ? $value : $this->unloadable($field, $value),
            FieldType::Timestamp => $value instanceof DateTimeInterface
                ? CarbonImmutable::instance($value)->utc()->format(self::TIMESTAMP_FORMAT)
                : $this->unloadable($field, $value),
            FieldType::Json => \is_array($value) || $value instanceof stdClass ? $value : $this->unloadable($field, $value),
            FieldType::Links => $this->unloadable($field, $value),
        };
    }

    /**
     * Links are a set: sorted, so two reads of the same set encode the same.
     *
     * @return list<string>
     */
    private function payloadLinks(Model $model, Field $field): array
    {
        $ids = $this->links($model, $field);

        if ($ids === null) {
            // An empty list would tell the client to drop every link it has.
            throw new LogicException(sprintf('%s: "%s" is not loaded on the model.', static::class, $field->name));
        }

        sort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * A JSON integer; it may arrive as `1.0`, which decodes as a float.
     *
     * @throws MappingRejection When it is beyond the range of a PHP integer.
     */
    private function integer(Field $field, mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (! \is_float($value) || floor($value) !== $value) {
            $this->unexpected($field, $value);
        }

        if ($value >= self::INT_LIMIT || $value < -self::INT_LIMIT) {
            throw new MappingRejection(new ContractIssue(
                '/'.$field->name,
                $value > 0 ? 'maximum' : 'minimum',
                'The integer is out of the range the server can store.',
            ));
        }

        return (int) $value;
    }

    /**
     * A `date-time` as a UTC instant, cut to the millisecond.
     *
     * The cut happens here, not in MySQL, which rounds when it stores into
     * `datetime(3)`: `.9995` would become the next second, and the
     * last-write-wins comparison would see another time than the client sent.
     *
     * @throws MappingRejection On a leap second, which `datetime(3)` cannot hold.
     */
    private function instant(Field $field, string $value): CarbonImmutable
    {
        if (preg_match(self::DATE_TIME_PATTERN, $value, $parts, PREG_UNMATCHED_AS_NULL) !== 1) {
            $this->unexpected($field, $value);
        }

        [, $date, $hoursMinutes, $seconds, $fraction, $offset] = $parts;

        if ($seconds === '60') {
            throw new MappingRejection(new ContractIssue(
                '/'.$field->name,
                'format',
                'A leap second cannot be stored.',
            ));
        }

        $millis = substr(str_pad($fraction ?? '', 3, '0'), 0, 3);
        $offset = $offset === null || $offset === '-00:00' ? '+00:00' : $offset;
        $normalized = "{$date} {$hoursMinutes}:{$seconds}.{$millis} {$offset}";
        $instant = CarbonImmutable::rawCreateFromFormat('!Y-m-d H:i:s.v P', $normalized);

        // A value the format parses but moves (e.g. February 30th) did not
        // pass the contract's date-time check.
        if (! $instant instanceof CarbonImmutable || $instant->format('Y-m-d H:i:s.v P') !== $normalized) {
            $this->unexpected($field, $value);
        }

        return $instant->utc();
    }

    /**
     * @return list<string>
     */
    private function uuidList(Field $field, mixed $value): array
    {
        if (! \is_array($value) || ! array_is_list($value)) {
            $this->unexpected($field, $value);
        }

        return array_map(fn (mixed $id): string => \is_string($id) ? $id : $this->unexpected($field, $value), $value);
    }

    /**
     * @throws LogicException Always: the record was not validated against the contract.
     */
    private function unexpected(Field $field, mixed $value): never
    {
        throw new LogicException(sprintf(
            '%s cannot map %s into "%s": validate the record first.',
            static::class,
            get_debug_type($value),
            $field->name,
        ));
    }

    /**
     * @throws LogicException Always: the model holds what the contract cannot carry.
     */
    private function unloadable(Field $field, mixed $value): never
    {
        throw new LogicException(sprintf(
            '%s cannot send the %s attribute holding %s as "%s".',
            static::class,
            $field->column,
            get_debug_type($value),
            $field->name,
        ));
    }
}
