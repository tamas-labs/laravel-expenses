<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Sync;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use TamasLabs\LaravelExpenses\Contract\ContractIssue;
use TamasLabs\LaravelExpenses\Contract\ContractViolation;
use TamasLabs\LaravelExpenses\Support\PackageConfig;

/**
 * The log line of every push (spec 08, 3.6): who pushed from which device,
 * how many records of each resource ended how, and how long it took. The
 * request id comes with the log's context.
 *
 * Never a value of a record: of a rejected one only its id and the path and
 * keyword of its issues, which tell what to look at without telling what the
 * user wrote.
 */
final class PushLog
{
    public static function pushed(Authenticatable $user, ?string $deviceId, PushResult $result, float $seconds): void
    {
        $counts = [];
        $rejected = [];

        foreach ($result->results as $resource => $group) {
            foreach ($group as $record) {
                $status = $record->status->value;
                $counts[$resource][$status] = ($counts[$resource][$status] ?? 0) + 1;

                if ($record->status === RecordStatus::Rejected) {
                    $rejected[] = ['resource' => $resource, 'id' => $record->id, 'issues' => self::issues($record->issues)];
                }
            }
        }

        $context = [...self::who($user, $deviceId), 'records' => $counts, 'durationMs' => self::milliseconds($seconds)];

        if ($rejected === []) {
            Log::channel(PackageConfig::logChannel())->info('Expenses push.', $context);
        } else {
            Log::channel(PackageConfig::logChannel())->warning('Expenses push with rejected records.', [...$context, 'rejected' => $rejected]);
        }
    }

    /**
     * A push refused as a whole (4xx): nothing was written.
     */
    public static function refused(Authenticatable $user, ?string $deviceId, ContractViolation|ProtocolError $error, float $seconds): void
    {
        $context = [...self::who($user, $deviceId), 'durationMs' => self::milliseconds($seconds)];

        $context += $error instanceof ContractViolation
            ? ['code' => ContractViolation::CODE, 'issues' => self::issues($error->issues)]
            : ['code' => $error->errorCode, 'resource' => $error->resource];

        Log::channel(PackageConfig::logChannel())->warning('Expenses push refused.', $context);
    }

    /**
     * @return array{user: mixed, device: string|null}
     */
    private static function who(Authenticatable $user, ?string $deviceId): array
    {
        return ['user' => $user instanceof Model ? $user->getAttribute('uuid') : null, 'device' => $deviceId];
    }

    /**
     * @param  list<ContractIssue>  $issues
     * @return list<string> `path keyword`
     */
    private static function issues(array $issues): array
    {
        return array_map(static fn (ContractIssue $issue): string => $issue->path.' '.$issue->keyword, $issues);
    }

    private static function milliseconds(float $seconds): int
    {
        return (int) round($seconds * 1000);
    }
}
