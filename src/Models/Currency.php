<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use TamasLabs\LaravelExpenses\Models\Concerns\HasSyncBookkeeping;

/**
 * A currency of the global reference list: no owner, fixed UUIDs the client
 * knows too, seeded by the package's migration and read-only for clients.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $symbol
 * @property int $minor_unit
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property int $server_seq
 * @property CarbonImmutable $synced_at
 */
final class Currency extends Model
{
    use HasSyncBookkeeping;

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'currencies';

    protected $keyType = 'string';

    protected $hidden = ['server_seq', 'synced_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'minor_unit' => 'integer',
        ];
    }
}
