<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Sync;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use stdClass;
use TamasLabs\LaravelExpenses\Contract\ContractIssue;
use TamasLabs\LaravelExpenses\Contract\ContractValidator;
use TamasLabs\LaravelExpenses\Contract\ContractVersion;
use TamasLabs\LaravelExpenses\Contract\ContractViolation;
use TamasLabs\LaravelExpenses\Mapping\MappedRecord;
use TamasLabs\LaravelExpenses\Mapping\MappingRejection;
use TamasLabs\LaravelExpenses\Models\BudgetPocket;
use TamasLabs\LaravelExpenses\Models\SyncModel;
use TamasLabs\LaravelExpenses\Registry\ResourceRegistry;
use TamasLabs\LaravelExpenses\Rules\DomainValidator;
use TamasLabs\LaravelExpenses\Rules\RuleContext;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Sync\Events\RecordsPushed;

/**
 * Applies a push (spec 06, 5.3): decides every record — accepted, stale or
 * rejected — and writes the accepted changes in one transaction.
 *
 * What needs no database (the envelope, the schema, the mapping) runs before
 * the transaction. Inside it, the user's lock serialises the user's pushes;
 * the rules and last-write-wins run under that lock alone, and the global
 * sequence lock is only taken for the short write at the end (4.2).
 */
final class PushHandler
{
    /**
     * The request document, for the error envelope.
     */
    public const string DOCUMENT = 'push-request';

    /**
     * Deadlocks and lock wait timeouts retry the whole transaction (4.2).
     */
    public const int ATTEMPTS = 3;

    /**
     * The contract's `uuid`: a record needs one to be answered for.
     */
    private const string UUID_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly ContractValidator $validator,
        private readonly DomainValidator $rules,
        private readonly SyncStore $store,
    ) {}

    /**
     * @param  mixed  $body  The request body, decoded with objects.
     *
     * @throws ContractViolation When the envelope does not match the push request.
     * @throws ProtocolError When the push is refused as a whole.
     */
    public function push(Authenticatable $user, mixed $body, ContractVersion $client): PushResult
    {
        $groups = $this->envelope($body, $client);
        $prepared = $this->prepare($groups);

        return DB::transaction(fn (): PushResult => $this->apply($user, $groups, $prepared), self::ATTEMPTS);
    }

    /**
     * The request's groups, checked as a whole (3.2): nothing is written when
     * any of this fails.
     *
     * @return array<string, list<stdClass>> Resource → records, in the request's order.
     *
     * @throws ContractViolation|ProtocolError
     */
    private function envelope(mixed $body, ContractVersion $client): array
    {
        if (! $body instanceof stdClass) {
            throw new ContractViolation(self::DOCUMENT, [new ContractIssue('/', 'type', 'must be object')]);
        }

        $fields = get_object_vars($body);
        $issues = [];

        foreach (array_keys($fields) as $field) {
            if ($field !== 'records') {
                $issues[] = new ContractIssue('/', 'additionalProperties', 'must NOT have additional properties');
            }
        }

        $records = $fields['records'] ?? null;

        if (! \array_key_exists('records', $fields)) {
            $issues[] = new ContractIssue('/', 'required', "must have required property 'records'");
        } elseif (! $records instanceof stdClass) {
            $issues[] = new ContractIssue('/records', 'type', 'must be object');
        }

        if ($issues !== [] || ! $records instanceof stdClass) {
            throw new ContractViolation(self::DOCUMENT, $issues);
        }

        // The schema would only call an unknown group an additional property.
        $groups = [];

        foreach (get_object_vars($records) as $resource => $group) {
            $resource = (string) $resource;

            if (! $this->writable($resource, $client)) {
                throw ProtocolError::resourceNotWritable($resource);
            }

            $groups[$resource] = $group;
        }

        foreach ($groups as $resource => $group) {
            if (! \is_array($group)) {
                $issues[] = new ContractIssue("/records/{$resource}", 'type', 'must be array');
            } elseif ($group === []) {
                $issues[] = new ContractIssue("/records/{$resource}", 'minItems', 'must NOT have fewer than 1 items');
            }
        }

        if ($issues !== []) {
            throw new ContractViolation(self::DOCUMENT, $issues);
        }

        /** @var array<string, list<mixed>> $groups JSON arrays decode as lists. */
        $count = array_sum(array_map(\count(...), $groups));

        if ($count > PackageConfig::pushMaxRecords()) {
            throw ProtocolError::tooManyRecords($count, PackageConfig::pushMaxRecords());
        }

        // A record is answered by its id, so one without a usable id fails the
        // envelope instead of getting a result of its own.
        foreach ($groups as $resource => $group) {
            foreach ($group as $index => $record) {
                if (! $record instanceof stdClass || ! \is_string($record->id ?? null) || preg_match(self::UUID_PATTERN, $record->id) !== 1) {
                    array_push($issues, ...$this->recordIssues($resource, $record, "/records/{$resource}/{$index}"));
                }
            }
        }

        if ($issues !== []) {
            throw new ContractViolation(self::DOCUMENT, $issues);
        }

        /** @var array<string, list<stdClass>> $groups Each one checked above. */
        foreach ($groups as $resource => $group) {
            $seen = [];

            foreach ($group as $record) {
                $id = self::id($record);

                if (isset($seen[$id])) {
                    throw ProtocolError::duplicateRecord($resource, $id);
                }

                $seen[$id] = true;
            }
        }

        return $groups;
    }

    /**
     * Checks each record against its schema (02) and maps it (04): a record
     * failing either is rejected here, before any lock.
     *
     * @param  array<string, list<stdClass>>  $groups
     * @return array<string, array<int, MappedRecord|RecordResult>>
     */
    private function prepare(array $groups): array
    {
        $prepared = [];

        foreach ($groups as $resource => $records) {
            $mapper = $this->registry->mapper($resource);

            foreach ($records as $index => $record) {
                $id = self::id($record);
                $result = $this->validator->validate($resource, $record);

                if (! $result->valid) {
                    $prepared[$resource][$index] = RecordResult::rejected($id, $result->issues);

                    continue;
                }

                try {
                    $prepared[$resource][$index] = $mapper->toAttributes($record);
                } catch (MappingRejection $rejection) {
                    $prepared[$resource][$index] = RecordResult::rejected($id, [$rejection->issue]);
                }
            }
        }

        return $prepared;
    }

    /**
     * The transaction: lock, decide resource by resource in the push order,
     * then reserve the sequence numbers and write.
     *
     * @param  array<string, list<stdClass>>  $groups
     * @param  array<string, array<int, MappedRecord|RecordResult>>  $prepared
     */
    private function apply(Authenticatable $user, array $groups, array $prepared): PushResult
    {
        $this->store->lockUser($user);

        $context = new RuleContext($user, $this->store);
        $results = [];
        $writes = [];

        foreach ($this->registry->pushable() as $definition) {
            $resource = $definition->name;

            if (! isset($prepared[$resource])) {
                continue;
            }

            [$results[$resource], $rows] = $this->decide($resource, $groups[$resource], $prepared[$resource], $context);

            if ($rows !== []) {
                $writes[$resource] = $rows;
            }
        }

        $written = $this->write($user, $writes, $context);

        if ($written !== []) {
            DB::afterCommit(static fn () => Event::dispatch(new RecordsPushed($user, $written)));
        }

        return new PushResult(self::inRequestOrder($groups, $results), $written);
    }

    /**
     * Decides the records of one resource (5.3/3): last-write-wins against
     * the server's version, then the domain rules on the records that would
     * change the server or match it.
     *
     * A record the server's version beats is stale whatever the rules would
     * say of it: it does not change the server, so the rules, which judge
     * the state the push leaves behind, must not count it as a change (the
     * unique rule would free its old key for another record).
     *
     * @param  list<stdClass>  $records
     * @param  array<int, MappedRecord|RecordResult>  $prepared
     * @return array{array<int, RecordResult>, list<SyncModel>} The results by index, and the rows to write.
     */
    private function decide(string $resource, array $records, array $prepared, RuleContext $context): array
    {
        $mapper = $this->registry->mapper($resource);
        $results = [];
        $pushed = [];

        foreach ($prepared as $index => $item) {
            if ($item instanceof RecordResult) {
                $results[$index] = $item;
                $context->reject($resource, $item->id);
            } else {
                $pushed[$index] = $this->model($resource, $item);
            }
        }

        $existing = $this->store->existing($resource, $context->user, array_values(array_map(static fn (SyncModel $model): string => $model->id, $pushed)));
        $context->setExisting($resource, $existing);

        $candidates = [];
        $outcomes = [];

        foreach ($pushed as $index => $model) {
            $server = $existing[$model->id] ?? null;
            $outcome = Lww::decide($model, $server, $mapper);

            if ($outcome === LwwOutcome::ServerWins && $server !== null) {
                $results[$index] = RecordResult::stale($model->id, $mapper->toPayload($server));

                continue;
            }

            $candidates[$index] = $records[$index];
            $outcomes[$index] = $outcome;
        }

        $issues = $candidates === [] ? [] : $this->rules->validate($resource, $candidates, $context);
        $rows = [];

        foreach ($candidates as $index => $record) {
            $model = $pushed[$index];
            $found = $issues[$index] ?? [];

            if ($found !== []) {
                $results[$index] = RecordResult::rejected($model->id, $found);
                $context->reject($resource, $model->id);

                continue;
            }

            $results[$index] = RecordResult::accepted($model->id);
            $context->accept($resource, $record);

            if ($outcomes[$index] === LwwOutcome::PushedWins) {
                $model->exists = isset($existing[$model->id]);
                $rows[] = $model;
            }
        }

        return [$results, $rows];
    }

    /**
     * Reserves one sequence number per row and writes the rows in the push
     * order (5.3/4–5).
     *
     * @param  array<string, list<SyncModel>>  $writes  Resource → rows, in the push order.
     * @return array<string, int> Resource → the rows written.
     */
    private function write(Authenticatable $user, array $writes, RuleContext $context): array
    {
        $count = array_sum(array_map(\count(...), $writes));

        if ($count === 0) {
            return [];
        }

        [$seq] = $this->store->allocateSequence($count);
        $now = CarbonImmutable::now('UTC');
        $written = [];

        foreach ($writes as $resource => $rows) {
            $this->store->releaseUniqueKeys($resource, $user, $this->movingKeys($resource, $rows, $context->existing($resource)), $now);
            $this->store->write($resource, $user, $rows, $seq, $now);

            $this->store->replacePocketCategories($user, self::pocketCategories($rows));

            $seq += \count($rows);
            $written[$resource] = \count($rows);
        }

        return $written;
    }

    /**
     * The existing live rows whose unique key the write moves: renamed (or
     * moved to another parent) or turned into a tombstone. Their keys are
     * released first, so that two records may swap a key in one push (5.3).
     *
     * @param  list<SyncModel>  $rows
     * @param  array<string, SyncModel>  $existing
     * @return list<string>
     */
    private function movingKeys(string $resource, array $rows, array $existing): array
    {
        $mapper = $this->registry->mapper($resource);
        $columns = array_map(static fn (string $field): string => $mapper->field($field)->column, $this->rules->uniqueFields($resource));

        if ($columns === []) {
            return [];
        }

        $ids = [];

        foreach ($rows as $row) {
            $stored = $existing[$row->id] ?? null;

            if ($stored === null || $stored->deleted_at !== null) {
                continue;
            }

            $moves = $row->deleted_at !== null;

            foreach ($columns as $column) {
                $moves = $moves || $row->getAttribute($column) !== $stored->getAttribute($column);
            }

            if ($moves) {
                $ids[] = $row->id;
            }
        }

        return $ids;
    }

    /**
     * The pushed record as an unsaved model of its resource.
     */
    private function model(string $resource, MappedRecord $mapped): SyncModel
    {
        $model = new ($this->registry->ownedModel($resource));
        $model->forceFill($mapped->attributes);

        if ($model instanceof BudgetPocket) {
            $model->categoryIds = $mapped->links['categoryIds'] ?? throw new LogicException('A mapped pocket has no categoryIds.');
        }

        return $model;
    }

    /**
     * Whether the client may push the resource: pushable, and known to its
     * contract version (3.4).
     */
    private function writable(string $resource, ContractVersion $client): bool
    {
        if (! $this->registry->has($resource)) {
            return false;
        }

        $definition = $this->registry->get($resource);

        return $definition->clientWritable && $definition->applyOrder !== null && $definition->knownBy($client);
    }

    /**
     * A record's schema issues, with paths from the request's root. Never
     * empty: a record reaching here has no valid id.
     *
     * @return non-empty-list<ContractIssue>
     */
    private function recordIssues(string $resource, mixed $record, string $path): array
    {
        $issues = array_map(
            static fn (ContractIssue $issue): ContractIssue => new ContractIssue(
                $path.($issue->path === '/' ? '' : $issue->path),
                $issue->keyword,
                $issue->message,
                $issue->params,
            ),
            $this->validator->validate($resource, $record)->issues,
        );

        return $issues !== [] ? $issues : [new ContractIssue($path.'/id', 'pattern', 'must be a lowercase UUID')];
    }

    /**
     * @param  array<string, list<stdClass>>  $groups
     * @param  array<string, array<int, RecordResult>>  $results
     * @return array<string, list<RecordResult>>
     */
    private static function inRequestOrder(array $groups, array $results): array
    {
        $ordered = [];

        foreach ($groups as $resource => $records) {
            $ordered[$resource] = array_map(
                static fn (int $index): RecordResult => $results[$resource][$index] ?? throw new LogicException(sprintf('The %s record #%d got no result.', $resource, $index)),
                array_keys($records),
            );
        }

        return $ordered;
    }

    /**
     * The categories of the pockets among the rows, by pocket id.
     *
     * @param  list<SyncModel>  $rows
     * @return array<string, list<string>>
     */
    private static function pocketCategories(array $rows): array
    {
        $categoryIds = [];

        foreach ($rows as $row) {
            if ($row instanceof BudgetPocket) {
                $categoryIds[$row->id] = $row->categoryIds ?? throw new LogicException('A pushed pocket has no categories.');
            }
        }

        return $categoryIds;
    }

    private static function id(stdClass $record): string
    {
        return \is_string($record->id ?? null) ? $record->id : throw new LogicException('A record without an id passed the envelope.');
    }
}
