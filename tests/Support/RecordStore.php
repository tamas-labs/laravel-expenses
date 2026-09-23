<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Tests\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;
use stdClass;
use TamasLabs\LaravelExpenses\Database\Factories\SyncModelFactory;
use TamasLabs\LaravelExpenses\Models\BudgetPocket;
use TamasLabs\LaravelExpenses\Models\SyncModel;
use TamasLabs\LaravelExpenses\Registry\ResourceRegistry;
use TamasLabs\LaravelExpenses\Support\Json;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;

/**
 * Stores and reads records through the mappers the way the sync's storage
 * layer (spec 06) will, for the mapping tests: the owner and the bookkeeping
 * columns filled in by hand, a pocket's categories in the pivot.
 */
final class RecordStore
{
    public static function registry(): ResourceRegistry
    {
        return app(ResourceRegistry::class);
    }

    /**
     * Stores a record of an owned resource for the user.
     */
    public static function store(string $resource, stdClass $record, User $user): void
    {
        $mapped = self::registry()->mapper($resource)->toAttributes($record);
        $model = self::newModel($resource);

        $model->forceFill([
            ...$mapped->attributes,
            'user_id' => $user->getKey(),
            'server_seq' => SyncModelFactory::nextServerSeq(),
            'synced_at' => CarbonImmutable::now('UTC'),
        ])->save();

        foreach ($mapped->links['categoryIds'] ?? [] as $categoryId) {
            DB::table(PackageConfig::table('budget_pocket_categories'))->insert([
                'user_id' => $user->getKey(),
                'budget_pocket_id' => $model->getKey(),
                'category_id' => $categoryId,
            ]);
        }
    }

    /**
     * Stores every example of the resources a push applies before this one,
     * which are the parents its example refers to.
     */
    public static function storeParents(string $resource, User $user): void
    {
        $order = self::registry()->get($resource)->applyOrder ?? 0;

        foreach (self::registry()->pushable() as $parent) {
            if ($parent->applyOrder >= $order) {
                break;
            }

            for ($index = 0; $index < ContractSchema::exampleCount($parent->name); $index++) {
                self::store($parent->name, ContractSchema::example($parent->name, $index), $user);
            }
        }
    }

    /**
     * Reads an owned record back with a fresh query, the pocket's categories loaded.
     */
    public static function reload(string $resource, string $id, User $user): SyncModel
    {
        $class = self::newModel($resource)::class;
        $model = $class::query()->ownedBy($user)->whereKey($id)->sole();

        return $model instanceof BudgetPocket ? self::loadCategoryIds($model) : $model;
    }

    /**
     * Stores the record, reads it back and returns the mapper's payload, encoded.
     *
     * A `user` record becomes a new account instead of a row of `$user`.
     */
    public static function roundTrip(string $resource, stdClass $record, User $user): string
    {
        $id = \is_string($record->id ?? null) ? $record->id : throw new LogicException('The record has no id.');

        if ($resource === 'user') {
            $mapped = self::registry()->mapper('user')->toAttributes($record);
            (new User)->forceFill([...$mapped->attributes, 'password' => Hash::make('password')])->save();

            return self::encode('user', User::query()->where('uuid', $id)->sole());
        }

        self::store($resource, $record, $user);

        return self::encode($resource, self::reload($resource, $id, $user));
    }

    public static function encode(string $resource, Model $model): string
    {
        return Json::encode(self::registry()->mapper($resource)->toPayload($model));
    }

    public static function loadCategoryIds(BudgetPocket $pocket): BudgetPocket
    {
        $pocket->categoryIds = array_values(DB::table(PackageConfig::table('budget_pocket_categories'))
            ->where('user_id', $pocket->user_id)
            ->where('budget_pocket_id', $pocket->id)
            ->pluck('category_id')
            ->map(static fn (mixed $id): string => \is_string($id) ? $id : throw new LogicException('A pivot row has no category id.'))
            ->all());

        return $pocket;
    }

    private static function newModel(string $resource): SyncModel
    {
        $model = new (self::registry()->get($resource)->model);

        return $model instanceof SyncModel ? $model : throw new LogicException(sprintf('The %s resource is not owned by a user.', $resource));
    }
}
