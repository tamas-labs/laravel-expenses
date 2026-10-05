<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Tests\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Assert;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use TamasLabs\LaravelExpenses\Auth\DeviceSessions;
use TamasLabs\LaravelExpenses\Contract\ContractVersion;
use TamasLabs\LaravelExpenses\Database\Currencies;
use TamasLabs\LaravelExpenses\Database\SyncSequence;
use TamasLabs\LaravelExpenses\Support\Json;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;
use TamasLabs\LaravelExpenses\Tests\TestCase;

use function Pest\Laravel\call;

/**
 * Calls the sync endpoints the way a client does, and reads their answers
 * after checking them against the protocol documents.
 */
final class SyncApi
{
    /**
     * Moves the sequence counter past the rows the factories number by hand
     * (they start at 1001), so the push's numbers never collide with them
     * and a pull sees them as already committed.
     */
    public static function startSequenceAt(int $value): void
    {
        DB::table(PackageConfig::table(SyncSequence::TABLE))->where('id', 1)->update(['value' => $value]);
    }

    public static function sequenceValue(): int
    {
        return SyncSequence::state()['value'];
    }

    /**
     * A user whose account record is synced: its profile filled in and a
     * sequence number reserved, as the account endpoints (spec 07) will.
     */
    public static function userWithProfile(): User
    {
        $user = User::factory()->createOne();

        DB::transaction(static function () use ($user): void {
            $user->forceFill([
                'default_currency_id' => Currencies::HUF,
                'registered_at' => CarbonImmutable::parse('2026-09-14T08:30:00.000Z'),
                'profile_created_at' => CarbonImmutable::parse('2026-03-01T10:00:00.000Z'),
                'profile_updated_at' => CarbonImmutable::parse('2026-09-14T08:30:00.000Z'),
                'server_seq' => SyncSequence::reserve(1),
            ])->save();
        });

        return $user;
    }

    /**
     * @param  array<string, list<stdClass>>  $records  Resource → records.
     * @return TestResponse<Response>
     */
    public static function push(User $user, array $records, ?string $version = null, ?string $requestId = null): TestResponse
    {
        return self::pushRaw($user, Json::encode(['records' => (object) $records]), $version, $requestId);
    }

    /**
     * @return TestResponse<Response>
     */
    public static function pushRaw(User $user, string $body, ?string $version = null, ?string $requestId = null): TestResponse
    {
        self::actingAs($user);

        return call('POST', route('expenses.sync.push'), [], [], [], self::server($version, $requestId), $body);
    }

    /**
     * Pushes, expects a 200 matching `push-response`, and returns its `results`.
     *
     * @param  array<string, list<stdClass>>  $records
     */
    public static function pushOk(User $user, array $records, ?string $version = null): stdClass
    {
        $results = self::ok(self::push($user, $records, $version), 'protocol/push-response')->results ?? null;

        return $results instanceof stdClass ? $results : self::fail('The push response has no results.');
    }

    /**
     * @return TestResponse<Response>
     */
    public static function pull(User $user, ?string $cursor = null, int|string|null $limit = null, ?string $version = null): TestResponse
    {
        self::actingAs($user);

        $query = array_filter(['cursor' => $cursor, 'limit' => $limit], static fn (int|string|null $value): bool => $value !== null);

        return call('GET', route('expenses.sync.pull', $query), [], [], [], self::server($version));
    }

    /**
     * Pulls one page, expects a 200 matching `pull-response`, and returns it.
     */
    public static function pullOk(User $user, ?string $cursor = null, ?int $limit = null, ?string $version = null): stdClass
    {
        return self::ok(self::pull($user, $cursor, $limit, $version), 'protocol/pull-response');
    }

    /**
     * Expects a 4xx error envelope with the code; nothing else is checked.
     *
     * @param  TestResponse<Response>  $response
     */
    public static function assertError(TestResponse $response, int $status, string $code): stdClass
    {
        $response->assertStatus($status)->assertHeader(ContractVersion::HEADER, (string) ContractVersion::current());
        TestCase::assertMatchesContract('protocol/error', $response->baseResponse);

        $body = Json::decode((string) $response->getContent());
        $error = $body instanceof stdClass && ($body->error ?? null) instanceof stdClass ? $body->error : self::fail('No error envelope.');

        Assert::assertSame($code, $error->code ?? null);

        return $error;
    }

    /**
     * The status of every result, grouped as the response has them.
     *
     * @return array<string, list<string>>
     */
    public static function statuses(stdClass $results): array
    {
        $statuses = [];

        foreach (get_object_vars($results) as $resource => $group) {
            $statuses[(string) $resource] = array_map(
                static fn (mixed $result): string => $result instanceof stdClass && \is_string($result->status ?? null) ? $result->status : self::fail('A result has no status.'),
                \is_array($group) ? array_values($group) : self::fail('A result group is not a list.'),
            );
        }

        return $statuses;
    }

    /**
     * One result of the response.
     */
    public static function result(stdClass $results, string $resource, int $index = 0): stdClass
    {
        $group = $results->{$resource} ?? null;
        $result = \is_array($group) ? ($group[$index] ?? null) : null;

        return $result instanceof stdClass ? $result : self::fail("There is no {$resource} result #{$index}.");
    }

    /**
     * The `records` of a pull page.
     */
    public static function records(stdClass $page): stdClass
    {
        return ($page->records ?? null) instanceof stdClass ? $page->records : self::fail('The page has no records.');
    }

    /**
     * One group of a page's `records` (or of a push's `results`); `[]` when absent.
     *
     * @return list<stdClass>
     */
    public static function group(stdClass $groups, string $resource): array
    {
        $group = $groups->{$resource} ?? [];

        return \is_array($group)
            ? array_values(array_map(static fn (mixed $item): stdClass => $item instanceof stdClass ? $item : self::fail('A group holds a non-object.'), $group))
            : self::fail("The {$resource} group is not a list.");
    }

    /**
     * The cursor of a pull page.
     */
    public static function cursor(stdClass $page): string
    {
        return \is_string($page->cursor ?? null) ? $page->cursor : self::fail('The page has no cursor.');
    }

    /**
     * One issue of a rejected result (or of an error envelope's `issues`).
     */
    public static function issue(stdClass $result, int $index = 0): stdClass
    {
        $issue = \is_array($result->issues ?? null) ? ($result->issues[$index] ?? null) : null;

        return $issue instanceof stdClass ? $issue : self::fail("There is no issue #{$index}.");
    }

    /**
     * Every issue of a rejected result or an error envelope, as `path keyword`.
     *
     * @return list<string>
     */
    public static function issueKeys(stdClass $result): array
    {
        return array_map(
            static fn (stdClass $issue): string => self::string($issue->path ?? null).' '.self::string($issue->keyword ?? null),
            self::group($result, 'issues'),
        );
    }

    public static function string(mixed $value): string
    {
        return \is_string($value) ? $value : self::fail('Not a string: '.get_debug_type($value));
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private static function ok(TestResponse $response, string $document): stdClass
    {
        $response->assertOk()->assertHeader(ContractVersion::HEADER, (string) ContractVersion::current());
        TestCase::assertMatchesContract($document, $response->baseResponse);

        $body = Json::decode((string) $response->getContent());

        return $body instanceof stdClass ? $body : self::fail('The response is not an object.');
    }

    /**
     * Authenticates the next request with a Sanctum token holding the sync's
     * ability; the real tokens are the account tests' (spec 07).
     */
    private static function actingAs(User $user): void
    {
        Sanctum::actingAs($user, [DeviceSessions::ABILITY]);
    }

    /**
     * @return array<string, string>
     */
    private static function server(?string $version, ?string $requestId = null): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_EXPENSES_CONTRACT' => $version ?? (string) ContractVersion::current(),
            ...($requestId === null ? [] : ['HTTP_X_REQUEST_ID' => $requestId]),
        ];
    }

    private static function fail(string $message): never
    {
        Assert::fail($message);
    }
}
