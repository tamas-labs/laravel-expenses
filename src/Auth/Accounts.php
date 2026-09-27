<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Auth;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SensitiveParameter;
use stdClass;
use TamasLabs\LaravelExpenses\Contract\ContractIssue;
use TamasLabs\LaravelExpenses\Contract\ContractValidator;
use TamasLabs\LaravelExpenses\Contract\ContractViolation;
use TamasLabs\LaravelExpenses\Mapping\MappingRejection;
use TamasLabs\LaravelExpenses\Mapping\RecordMapper;
use TamasLabs\LaravelExpenses\Registry\ResourceRegistry;
use TamasLabs\LaravelExpenses\Rules\Batch\CurrencyNotRetired;
use TamasLabs\LaravelExpenses\Rules\Batch\ReferencesExist;
use TamasLabs\LaravelExpenses\Sync\Lww;
use TamasLabs\LaravelExpenses\Sync\LwwOutcome;
use TamasLabs\LaravelExpenses\Sync\RecordStatus;
use TamasLabs\LaravelExpenses\Sync\SyncStore;

/**
 * Registration, sign-in and the profile (spec 07, 5.1, 5.2 and 5.5).
 *
 * The user row is a synced record: every write of it reserves a sequence
 * number, under the user's lock when the user exists, as a push does (06, 4.2).
 * Bound as a singleton; it keeps no state but the decoy hash.
 */
final class Accounts
{
    /**
     * Deadlocks and lock wait timeouts retry the whole transaction.
     */
    public const int ATTEMPTS = 3;

    /**
     * The fields of the `user` record a client may change (5.5).
     */
    public const array WRITABLE = ['displayName', 'defaultCurrencyId', 'updatedAt'];

    /**
     * The fields that have to match the server's.
     */
    public const array READ_ONLY = ['id', 'email', 'registeredAt', 'createdAt'];

    /**
     * A hash of no one's password, by hasher driver: checked against when
     * the account does not exist, so the answer takes as long as a real
     * check (4.5).
     *
     * @var array<string, string>
     */
    private static array $decoyHashes = [];

    public function __construct(
        private readonly SyncStore $store,
        private readonly AccountStore $accounts,
        private readonly DeviceSessions $sessions,
        private readonly ContractValidator $validator,
        private readonly ResourceRegistry $registry,
    ) {}

    /**
     * Creates the account and signs the device in (5.1). The fields are
     * already normalized and checked against `register-request`.
     *
     * @throws AuthError When the password or the currency is refused (validation_failed), or the email is taken.
     */
    public function register(string $email, #[SensitiveParameter] string $password, string $displayName, string $deviceId, ?string $defaultCurrencyId): SignedIn
    {
        $issues = [
            ...PasswordPolicy::issues($password),
            ...$this->currencyIssues($defaultCurrencyId, '/defaultCurrencyId'),
        ];

        if ($issues !== []) {
            throw AuthError::validationFailed('register-request', $issues);
        }

        if ($this->accounts->emailTaken($email)) {
            throw AuthError::emailTaken();
        }

        $hash = Hash::make($password);

        try {
            return DB::transaction(function () use ($email, $hash, $displayName, $deviceId, $defaultCurrencyId): SignedIn {
                $now = CarbonImmutable::now('UTC');
                $user = UserModel::make();

                $user->forceFill([
                    'name' => $displayName,
                    'email' => $email,
                    $user->getAuthPasswordName() => $hash,
                    'default_currency_id' => $defaultCurrencyId,
                    'registered_at' => $now,
                    'profile_created_at' => $now,
                    'profile_updated_at' => $now,
                ]);

                [$seq] = $this->store->allocateSequence(1);
                $this->store->saveAccount($user, $seq);

                return new SignedIn($user, $this->sessions->signIn($user, $deviceId));
            }, self::ATTEMPTS);
        } catch (UniqueConstraintViolationException $exception) {
            // Registered in parallel, between the check and the insert. Any
            // other key clash is not the client's to hear about.
            throw $this->accounts->emailTaken($email) ? AuthError::emailTaken() : $exception;
        }
    }

    /**
     * Checks the credentials and signs the device in (5.2).
     *
     * Every failure is the same `invalid_credentials`, and an unknown email
     * costs a hash check too.
     *
     * @throws AuthError When the credentials are wrong, or too many attempts failed.
     */
    public function login(string $email, #[SensitiveParameter] string $password, string $deviceId, string $ip): SignedIn
    {
        Throttle::ensureLoginAllowed($email, $ip);

        $user = $this->accounts->findByEmail($email);
        $valid = Hash::check($password, $user?->getAuthPassword() ?? self::decoyHash());

        if ($user === null || ! $valid) {
            Throttle::loginFailed($email, $ip);
            Log::info('Expenses sign-in failed.', ['email_hash' => hash('sha256', $email), 'ip' => $ip]);

            throw AuthError::invalidCredentials();
        }

        Throttle::loginSucceeded($email, $ip);

        $tokens = DB::transaction(function () use ($user, $deviceId): TokenPair {
            $this->store->lockUser($user);
            $this->completeProfile($user);

            return $this->sessions->signIn($user, $deviceId);
        }, self::ATTEMPTS);

        return new SignedIn($user, $tokens);
    }

    /**
     * Applies a `PATCH /me` (5.5): the full `user` record, of which only the
     * writable fields may differ, decided by last-write-wins like a pushed
     * record.
     *
     * @param  mixed  $record  The body, decoded with objects.
     * @return array{status: string, record?: array<string, mixed>} The `me-update-result`.
     *
     * @throws ContractViolation When the record does not match the `user` schema.
     * @throws AuthError When it changes a read-only field, or names a currency it may not.
     */
    public function updateProfile(Model&Authenticatable $user, mixed $record): array
    {
        $result = $this->validator->validate('user', $record);

        if (! $result->valid || ! $record instanceof stdClass) {
            throw new ContractViolation('user', $result->issues);
        }

        $mapper = $this->mapper();

        try {
            $mapped = $mapper->toAttributes($record);
        } catch (MappingRejection $rejection) {
            throw new ContractViolation('user', [$rejection->issue]);
        }

        $pushed = UserModel::make()->forceFill($mapped->attributes);

        return DB::transaction(function () use ($user, $pushed, $mapper): array {
            $this->store->lockUser($user);

            $server = $this->store->account($user);
            $serverRecord = $mapper->toPayload($server);
            $pushedRecord = $mapper->toPayload($pushed);
            $issues = [];

            foreach (self::READ_ONLY as $field) {
                if ($pushedRecord[$field] !== $serverRecord[$field]) {
                    $issues[] = new ContractIssue('/'.$field, 'readOnly', 'The field cannot be changed.');
                }
            }

            if ($issues !== []) {
                throw AuthError::fieldNotWritable($issues);
            }

            $outcome = Lww::decide($pushed, $server, $mapper);

            if ($outcome === LwwOutcome::ServerWins) {
                return ['status' => RecordStatus::Stale->value, 'record' => $serverRecord];
            }

            if ($outcome === LwwOutcome::PushedWins) {
                $this->writeProfile($server, $pushed, $mapper);
            }

            return ['status' => RecordStatus::Accepted->value];
        }, self::ATTEMPTS);
    }

    /**
     * The body of the register, login and `/me` answers: the `user` record,
     * the device's tokens when there are new ones, and the account's state
     * outside the record.
     *
     * @return array{user: array<string, mixed>, tokens?: TokenPair, account: array{emailVerified: bool}}
     */
    public function payload(Model&MustVerifyEmail $user, ?TokenPair $tokens = null): array
    {
        $payload = ['user' => $this->mapper()->toPayload($user)];

        if ($tokens !== null) {
            $payload['tokens'] = $tokens;
        }

        $payload['account'] = ['emailVerified' => $user->hasVerifiedEmail()];

        return $payload;
    }

    /**
     * The currency rule of an account (05, S1 and S3): `null`, or a known
     * currency that is not retired. `$keep` is the one the account already
     * has, which it may keep even when retired.
     *
     * @return list<ContractIssue>
     */
    private function currencyIssues(?string $currencyId, string $path, ?string $keep = null): array
    {
        if ($currencyId === null || $currencyId === $keep) {
            return [];
        }

        $retired = $this->store->currencies()[$currencyId] ?? null;

        return match ($retired) {
            null => [new ContractIssue($path, ReferencesExist::KEYWORD, 'The currency does not exist.', ['resource' => 'currency', 'id' => $currencyId])],
            true => [new ContractIssue($path, CurrencyNotRetired::KEYWORD, 'The currency is retired and cannot be chosen anew.', ['id' => $currencyId])],
            false => [],
        };
    }

    /**
     * Writes the changed profile with a new sequence number, under the
     * user's lock.
     *
     * @throws AuthError When the new currency is unknown or retired.
     */
    private function writeProfile(Model $server, Model $pushed, RecordMapper $mapper): void
    {
        $currencyColumn = $mapper->field('defaultCurrencyId')->column;
        $currencyId = $pushed->getAttribute($currencyColumn);
        $current = $server->getAttribute($currencyColumn);
        $issues = $this->currencyIssues(\is_string($currencyId) ? $currencyId : null, '/defaultCurrencyId', \is_string($current) ? $current : null);

        if ($issues !== []) {
            throw AuthError::validationFailed('user', $issues);
        }

        foreach (self::WRITABLE as $field) {
            $column = $mapper->field($field)->column;
            $server->setAttribute($column, $pushed->getAttribute($column));
        }

        [$seq] = $this->store->allocateSequence(1);
        $this->store->saveAccount($server, $seq);
    }

    /**
     * Gives a user created before the package (or outside it) the profile
     * the `user` record needs, and a sequence number, on the first sign-in.
     */
    private function completeProfile(Model $user): void
    {
        if ($user->getAttribute('registered_at') !== null) {
            return;
        }

        $now = CarbonImmutable::now('UTC');

        $user->forceFill([
            'registered_at' => $user->getAttribute('profile_created_at') ?? $now,
            'profile_created_at' => $user->getAttribute('profile_created_at') ?? $now,
            'profile_updated_at' => $now,
        ]);

        [$seq] = $this->store->allocateSequence(1);
        $this->store->saveAccount($user, $seq);
    }

    private function mapper(): RecordMapper
    {
        return $this->registry->mapper('user');
    }

    private static function decoyHash(): string
    {
        return self::$decoyHashes[Hash::getDefaultDriver()] ??= Hash::make(Str::random(40));
    }
}
