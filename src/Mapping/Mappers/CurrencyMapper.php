<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Mapping\Mappers;

use LogicException;
use TamasLabs\LaravelExpenses\Mapping\Field;
use TamasLabs\LaravelExpenses\Mapping\MappedRecord;
use TamasLabs\LaravelExpenses\Mapping\RecordMapper;

/**
 * Out only: the currencies are the server's reference list, and the client
 * may not write them.
 */
final class CurrencyMapper extends RecordMapper
{
    /**
     * @return list<Field>
     */
    protected function fields(): array
    {
        return [
            Field::uuid('id', 'id'),
            Field::text('code', 'code'),
            Field::text('name', 'name'),
            Field::text('symbol', 'symbol'),
            Field::int('minorUnit', 'minor_unit'),
            Field::timestamp('createdAt', 'created_at'),
            Field::timestamp('updatedAt', 'updated_at'),
            Field::timestamp('deletedAt', 'deleted_at')->nullable(),
        ];
    }

    /**
     * @throws LogicException Always: clients cannot write currencies.
     */
    public function toAttributes(object $record): MappedRecord
    {
        throw new LogicException('Currencies are read-only for clients; there is no incoming currency record to map.');
    }
}
