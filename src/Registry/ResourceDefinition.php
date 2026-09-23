<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Registry;

use Illuminate\Database\Eloquent\Model;
use TamasLabs\LaravelExpenses\Mapping\RecordMapper;

/**
 * One resource of the contract, as the package serves it.
 */
final readonly class ResourceDefinition
{
    /**
     * @param  string  $name  The contract's name, e.g. `payment-method`.
     * @param  class-string<Model>  $model  For `user`, the host's user model.
     * @param  class-string<RecordMapper>  $mapper
     * @param  bool  $clientWritable  Whether a client may push it.
     * @param  int|null  $applyOrder  Position in a push, parents first; null when not pushable.
     * @param  string  $since  The contract minor the resource appeared in.
     */
    public function __construct(
        public string $name,
        public string $model,
        public string $mapper,
        public Scope $scope,
        public bool $clientWritable,
        public ?int $applyOrder,
        public string $since,
    ) {}
}
