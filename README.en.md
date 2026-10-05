# tamas-labs/laravel-expenses

**Language:** [Magyar](README.hu.md) · English

A Laravel 13 package: the sync backend of the Expenses mobile app (React Native). It gives the client
registration, sign-in and token authentication, stores each user's records, and accepts (push) and
returns (pull) the client's changes according to the contract.

The shape of the records and of the sync protocol is defined by the
[`tamas-labs/expenses-schema`](https://github.com/tamas-labs/expenses-schema) contract. **The contract
is the source of truth:** the package defines no wire format of its own, and every payload it sends
matches the schema.

## Contents

1. [What it is, and what it is not](#what-it-is-and-what-it-is-not)
2. [Requirements](#requirements)
3. [Installation](#installation)
4. [Operations](#operations)
5. [The API](#the-api)
6. [Sync rules for the client developer](#sync-rules-for-the-client-developer)
7. [Development server](#development-server)
8. [Working on the package](#working-on-the-package)
9. [Versioning](#versioning)

## What it is, and what it is not

**The package provides:**

- the client's endpoints: registration, sign-in, token refresh, sign-out, password reset, email
  verification, the profile (`/me`), account deletion, push and pull;
- the tables of the synced resources (migrations) and the additions to the host's `users` table;
- the contract checks (JSON Schema), the domain rules, the last-write-wins decision and the pull built
  on a global sequence number;
- the protection (rate limits, body size), the maintenance commands and the logging.

**The host app's job** (the package gives documentation and services for these, but does not
implement them):

- running the web server, PHP and MySQL, and HTTPS;
- the mail setup (the package sends with Laravel's `mail` configuration);
- running the scheduler (the package schedules nothing on its own, see [Scheduling](#scheduling));
- the web account deletion page Google Play requires (see [Account deletion](#account-deletion));
- the password reset page and the page after verification, or app deep links for them.

There is no web interface, admin panel or reporting. Users' data is fully isolated from each other;
there is no shared data.

## Requirements

- PHP 8.3, 8.4 or 8.5, with the `pdo_mysql` extension
- Laravel 13
- MySQL 8.4 LTS or newer. No other database (MariaDB, PostgreSQL, SQLite) is supported: the package
  relies on generated columns and composite foreign keys.
- [Laravel Sanctum](https://laravel.com/docs/sanctum) 4.3.1 or newer (a dependency of the package)

## Installation

### 1. The two VCS repositories

Neither package is on Packagist; both come from their GitHub repositories, versioned by git tags.
Composer reads `repositories` from the root `composer.json` only, so the host app has to add **both**:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/tamas-labs/laravel-expenses" },
    { "type": "vcs", "url": "https://github.com/tamas-labs/expenses-schema" }
]
```

Both repositories are public; no GitHub token is needed.

### 2. The package

```bash
composer require tamas-labs/laravel-expenses:^0.1
```

Auto-discovery loads the service provider.

### 3. The User model

The package builds on the host's `users` table. The User model has to use two traits and implement
the `MustVerifyEmail` interface. The package checks this when it boots and fails with a clear message
if anything is missing:

```php
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use TamasLabs\LaravelExpenses\HasExpensesProfile;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasExpensesProfile, Notifiable;
}
```

If the model is not `App\Models\User`, set the `EXPENSES_USER_MODEL` environment variable (or the
`expenses.user_model` config). Password reset uses Laravel's default password broker, so
`auth.providers.users.model` must be the same model.

The migration adds columns to the `users` table (`uuid`, `default_currency_id`, `registered_at`,
`profile_created_at`, `profile_updated_at`, `server_seq`); if any of them exists already, it stops
with a clear message. Registration creates a user by filling `name`, `email`, `password` and the
profile columns (`forceFill`, `$fillable` does not matter). **If the host's `users` table has other
required columns, give them defaults**, or registration fails with a database error.

### 4. The two required environment variables

```dotenv
# The link in the password reset mail: an app deep link or a host page, with {token} and {email}
EXPENSES_PASSWORD_RESET_URL="expenses://reset-password?token={token}&email={email}"
# Where an opened verification link leads; an invalid link gets ?status=invalid appended
EXPENSES_EMAIL_VERIFIED_URL="https://example.com/email-verified"
```

Without them (and without step 3) the package refuses to boot, with a clear message. The package
discovery (`package:discover`, run by Composer) and `vendor:publish` work before they are set.

### 5. The config, Sanctum's token table and the migrations

```bash
php artisan vendor:publish --tag=expenses-config   # optional: config/expenses.php
php artisan install:api                            # Sanctum's personal_access_tokens table, if missing
php artisan migrate
```

Sanctum's token table migration belongs to the host: `install:api` or
`vendor:publish --tag=sanctum-migrations` creates it. The package's migrations run without
publishing. If the host's table names would clash with the package's (`categories`, `expenses`, …),
`EXPENSES_TABLE_PREFIX` (at most 7 characters, e.g. `exp_`) prefixes every package table. **Set it
before the first migration**; it cannot change afterwards. To customise the migrations, publish them
(`--tag=expenses-migrations`): the copies keep the original file names in `database/migrations` and
run instead of the package's, not next to them.

### 6. Mail

Registration sends a verification mail, a password reset request a reset mail, with Laravel's
`MustVerifyEmail`, `VerifyEmail` and `ResetPassword`, through the host's `mail` configuration. The
package sets the mails' links with `VerifyEmail::createUrlUsing()` and
`ResetPassword::createUrlUsing()`, **but only if the host has not** (in a service provider's
`boot()`). The mail's language comes from the request's `Accept-Language` header (`hu` or `en`,
otherwise the app default). The package ships a Hungarian translation of Laravel's default texts; the
host's `lang/hu.json` overrides it. A failure to send is logged; the registration still succeeds.

### Settings

Every key is in `config/expenses.php`, with comments:

| Key                                    | Default             | Purpose                                                      |
| -------------------------------------- | ------------------- | ------------------------------------------------------------ |
| `user_model`                           | `App\Models\User`   | the host's User model (`EXPENSES_USER_MODEL`)                |
| `database.table_prefix`                | `''`                | the package tables' prefix (`EXPENSES_TABLE_PREFIX`)         |
| `routes.prefix`                        | `api/expenses`      | the endpoints' URI prefix (`EXPENSES_ROUTE_PREFIX`)          |
| `routes.middleware`                    | `api`, `expenses.contract` | the endpoints' middleware; keep `expenses.contract`   |
| `auth.access_ttl` / `auth.refresh_ttl` | 60 minutes / 90 days | token lifetimes                                             |
| `auth.password_reset_url`              | —                   | required, see above                                          |
| `auth.email_verified_url`              | —                   | required, see above                                          |
| `auth.require_verified_email`          | `false`             | whether the sync needs a verified email                      |
| `sync.push_max_records`                | 500                 | the most records one push may carry                          |
| `sync.pull_default_limit` / `pull_max_limit` | 500 / 1000    | the pull's page size                                         |
| `rate_limits.push` / `pull` / `me`     | 30 / 120 / 30       | requests per minute and user                                 |
| `http.max_body_kb`                     | 4096                | the largest request body                                     |
| `pruning.tombstone_days`               | 180                 | how long tombstones are kept                                 |
| `logging.channel`                      | the host's default  | the package's log channel (`EXPENSES_LOG_CHANNEL`)           |

## Operations

### Scheduling

The package schedules nothing; suggested entries for the host's scheduler (`routes/console.php`):

```php
use Illuminate\Support\Facades\Schedule;
use TamasLabs\LaravelExpenses\Auth\RefreshToken;

Schedule::command('expenses:prune-tombstones')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [RefreshToken::class]])->daily();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('auth:clear-resets')->everyFifteenMinutes();
```

- **`expenses:prune-tombstones`** deletes for good the tombstones (deleted records) the server stored
  more than 180 days ago (`expenses.pruning.tombstone_days`, overridden by `--days`) that nothing
  refers to. The server's time counts, not the client's `deletedAt`. Children go before their
  parents, so one run can delete a whole chain. It works in batches of a thousand, each in its own
  transaction, and logs one line at the end. `--dry-run` only counts what it would delete.
  **Consequence:** a client whose cursor is older than the deleted tombstones gets
  `410 cursor_expired` and downloads everything again; in practice, a device that has not synced for
  more than 180 days.
- **`model:prune`** with the `RefreshToken` model deletes refresh tokens 7 days after they expired
  (until then a used token stays, so that reusing it reveals a theft).
- **`sanctum:prune-expired`**: the expired access tokens (every refresh makes one).
- **`auth:clear-resets`**: the expired password reset tokens.

### Web server and PHP limits

The package refuses a request body larger than 4096 KB with `413 payload_too_large`
(`expenses.http.max_body_kb`). So that the web server or PHP does not cut it first with an obscure
error, set their limits higher:

```nginx
client_max_body_size 8m;
```

```ini
post_max_size = 8M
```

A push of 500 records (with expense items and product `offData`) fits well within 4 MB.

### Rate limits

The sync and profile endpoints are limited per user and minute: push 30, pull 120,
`GET`/`PATCH`/`DELETE /me` together 30 (`expenses.rate_limits.*`). The pull's limit is higher because
a first download asks for many pages in a row. Above the limit comes `429 too_many_requests` with a
`Retry-After` header. The limiters are named (`expenses-push`, `expenses-pull`, `expenses-me`), so the
host can replace them:

```php
RateLimiter::for('expenses-pull', fn (Request $request) => Limit::perMinute(300)->by($request->user()?->getAuthIdentifier()));
```

The account endpoints have fixed brakes of their own: 5 failed sign-ins per minute per
`(email, IP)` and 20 per IP, 10 registrations and 5 reset mails per hour per IP, 3 verification mails
per hour per user, and 5 wrong passwords per minute per user on account deletion
(`429 too_many_attempts`).

**Behind a reverse proxy** (a load balancer, Cloudflare, nginx on another machine) Laravel's trusted
proxies setting (`bootstrap/app.php`: `$middleware->trustProxies(...)`) is required. Without it every
client would come from the proxy's IP, so the IP-based brakes would apply to everyone together, and
the verification link's signature would fail on the differing scheme and host.

### Logging

- **Request id:** every route of the package takes the client's `X-Request-Id` header when it is a
  valid UUID and makes one up otherwise. It sends it back on the response and, through Laravel's
  Context, adds it to every log line (`requestId`), on any channel. A failure the client saw can be
  found in the server's log.
- **Push summary:** after every push an `info` line (`Expenses push.`): the user's UUID, the device,
  the count per resource and status, the duration. With a `rejected` record the line is a `warning`
  and also lists the records' ids and the `path` + `keyword` pairs of their issues. A push refused as
  a whole gets a `warning` line with its error code too.
- **Never logged:** record content (names, amounts, notes), passwords, tokens. The package adds
  `password`, `token`, `accessToken` and `refreshToken` to the exception handler's `dontFlash` list
  and marks the parameters holding passwords and tokens with `#[SensitiveParameter]`. A failed sign-in
  logs only the SHA-256 hash of the email and the IP.
- **Channel:** `expenses.logging.channel` (`EXPENSES_LOG_CHANNEL`), by default the host's default
  channel.
- **Unexpected failure:** the exception goes to the host's exception handler (logging, error
  tracker); the client gets `500 server_error` with the request id and no internal detail, even with
  `APP_DEBUG` on.

### Octane

The package's services are stateless or hold compiled schemas only, so they are safe under Octane.
The request id lives in Laravel's Context, which Octane empties for every request.

### Account deletion

`DELETE …/me`, after the password is entered again, deletes all the account's data, devices and
tokens and finally the `users` row in one transaction. Google Play also requires deletion on the web:
that is the host's job, a simple page that identifies the user and calls the same service:

```php
app(\TamasLabs\LaravelExpenses\Auth\DeleteAccount::class)($user);
```

### Events

The host app can listen to them; the package itself does not:

- `TamasLabs\LaravelExpenses\Sync\Events\RecordsPushed`: a push wrote to the database (after the
  commit), with the user and the number of records written per resource.
- `TamasLabs\LaravelExpenses\Auth\Events\AccountDeleted`: an account was deleted (after the commit),
  with the user's contract `uuid`.
- Laravel's own `Verified` and `PasswordReset` events on verification and password change.

## The API

### Endpoints

The routes live under `expenses.routes.prefix` (default: `api/expenses`). The request and response
shapes are the schema repository's
[`schema/protocol/`](https://github.com/tamas-labs/expenses-schema/tree/main/schema/protocol)
documents.

| Endpoint                                 | Authentication | Body → response                               | Rate limit |
| ---------------------------------------- | -------------- | --------------------------------------------- | ---------- |
| `POST …/auth/register`                   | —              | `register-request` → 201 `auth-response`      | 10/hour/IP |
| `POST …/auth/login`                      | —              | `login-request` → `auth-response`             | 5 failures/min |
| `POST …/auth/refresh`                    | —              | `refresh-request` → `refresh-response`        | —          |
| `POST …/auth/logout`                     | access token   | — → 204                                       | —          |
| `POST …/auth/password/forgot`            | —              | `password-forgot-request` → 202               | 5/hour/IP  |
| `POST …/auth/password/reset`             | —              | `password-reset-request` → 204                | —          |
| `GET …/auth/email/verify/{uuid}/{hash}`  | signed link    | — → redirect                                  | —          |
| `POST …/auth/email/resend`               | access token   | — → 202                                       | 3/hour     |
| `GET …/me`                               | access token   | — → `me-response`                             | 30/min     |
| `PATCH …/me`                             | access token   | the full `user` record → `me-update-result`   | 30/min     |
| `DELETE …/me`                            | access token   | `account-delete-request` → 204                | 30/min     |
| `POST …/sync/push`                       | access token   | `push-request` → `push-response`              | 30/min     |
| `GET …/sync/pull?cursor=<c>&limit=<n>`   | access token   | — → `pull-response`                           | 120/min    |

Route names are prefixed with `expenses.` (e.g. `expenses.sync.push`). Every route gets the
`expenses.routes.middleware` middleware, except the verification link, which the user opens in a
browser.

### Headers

| Header                | Direction        | Meaning                                                                |
| --------------------- | ---------------- | ---------------------------------------------------------------------- |
| `X-Expenses-Contract` | request, response | the contract version (`<major>.<minor>`). Required on every request; the response carries the server's. A different major or a newer minor: 400. |
| `Authorization`       | request          | `Bearer <access token>` on the protected endpoints                     |
| `X-Request-Id`        | request, response | the request's log id (UUID); the client may send one, the server always returns it |
| `Accept-Language`     | request          | the language of the mails (`hu`, `en`)                                 |
| `Retry-After`         | response         | with 429: the seconds to wait before the next request                  |

### Authentication

- **Access token:** a Sanctum personal access token with the `expenses:access` ability, one per
  device (named `device:<deviceId>`), valid for 60 minutes. Without a token: 401 `unauthenticated`;
  with a token lacking the ability: 403 `forbidden`.
- **Refresh token:** valid for 90 days, sent only in the body of `/auth/refresh`. Every refresh uses
  it up and hands out a new pair, so the expiry slides. Presenting a used refresh token again
  suggests a theft: the token chain and the device's access tokens are revoked
  (401 `refresh_token_reused`). The server stores only its SHA-256 hash.
- **Devices:** a device (`deviceId`) belongs to one account at a time. Signing in to another account
  moves the device, and the old account's tokens on it are revoked. Signing out revokes the device's
  tokens and unbinds it.
- **Sign-in:** every failure is the same 401 `invalid_credentials`; an unknown account still costs a
  hash check.
- **Password:** at least 8 characters; at most 72 bytes with bcrypt, 256 characters with another
  hasher. A password reset revokes every token and device of the account.
- **Email verification:** not a condition of the sync by default. With
  `expenses.auth.require_verified_email` on, the sync and profile changes answer 403
  `email_not_verified` until the address is verified.

### Error codes

Every request-level error has the `protocol/error` shape: `{"error": {"code": …, "message": …}}`,
with `resource`, `issues`, `serverVersion` or `requestId` where they apply. The client decides on the
`code`; the `message` is informative only. Record-level push failures do not use it but the
`rejected` result. The schema repository's
[known error codes](https://github.com/tamas-labs/expenses-schema/blob/main/README.en.md) table
describes each code.

| Status | Code                                                     | When                                                     |
| ------ | -------------------------------------------------------- | -------------------------------------------------------- |
| 400    | `contract_version_missing`, `_malformed`, `_unsupported` | `X-Expenses-Contract` is missing, malformed or not supported |
| 401    | `unauthenticated`                                        | no, an expired or an invalid access token                |
| 401    | `invalid_credentials`                                    | wrong email or password                                  |
| 401    | `refresh_token_invalid`, `refresh_token_reused`          | the refresh's failures                                   |
| 403    | `forbidden`, `email_not_verified`                        | the token grants no access; the email is not verified    |
| 410    | `cursor_expired`                                         | the cursor is older than the pruned tombstones: download everything again |
| 413    | `payload_too_large`                                      | the request body is over the limit                       |
| 422    | `contract_violation`                                     | the request does not match the schema (`issues`)         |
| 422    | `validation_failed`, `field_not_writable`, `email_taken`, `reset_token_invalid` | the account endpoints' failures |
| 422    | `resource_not_writable`, `duplicate_record`, `too_many_records`, `cursor_malformed` | the push's and the pull's failures |
| 429    | `too_many_requests`, `too_many_attempts`                 | the rate limit, or the account endpoints' brake (`Retry-After`) |
| 500    | `server_error`                                           | an unexpected failure; the `requestId` finds it in the server's log |

## Sync rules for the client developer

The full protocol is described in the schema repository's
[sync protocol](https://github.com/tamas-labs/expenses-schema/blob/main/README.en.md) chapter. The
client's obligations:

1. **`X-Expenses-Contract` on every request.**
2. **Push and pull in any order.** The client's own accepted records come back in the next pull (with
   a new sequence number); that is harmless, a no-op locally.
3. **`stale` → the response's `record` replaces the local version**, and the record becomes clean.
   The server's version won, and the client's cursor may already be past it.
4. **`rejected` → the record stays dirty**; the client does not retry it unchanged in an endless loop:
   the failure needs a data fix.
5. **A `unique` failure with `conflictingId` → the merge** switches the local record's `id` to the
   server's.
6. **Apply the pull's pages in one transaction**, with deferred foreign key checks
   (`PRAGMA defer_foreign_keys = ON`), until `hasMore` is `false`: a page may bring a child whose
   parent comes on the next page.
7. **Apply last-write-wins on pull too:** the later `updatedAt` wins; **on a tie the server's version
   stays**.
8. **`410 cursor_expired` → download everything again** without a cursor, but push the local changes
   not yet uploaded first.
9. **The cursor is opaque:** the client only stores it and sends it back.
10. **`429` → wait for `Retry-After`**, do not retry at once. One push carries at most 500 records; a
    larger set of changes is several pushes.
11. **For bug reports, the `X-Request-Id`:** the client may send its own or keep the response's.

## Development server

The React Native client's sync needs a running server. The repository provides one with Testbench's
Workbench:

```bash
docker compose up app
```

This starts MySQL (`dev-mysql`, kept in a volume, so it survives a restart), runs the migrations and
`composer dev:seed`, and serves the API on port `8080`: `http://localhost:8080/api/expenses`.

- **Test user:** `dev@example.com` / `password`, with a verified email address. Alongside it, the
  client's seed categories, subcategories and payment methods under their fixed seed UUIDs
  (`00000000-0000-4000-8100-…`, `…-8200-…`, `…-8300-…`), with timestamps older than a device's own
  seed, so the client's merge can be tried too. The seed can run again.
- **Mails:** the `log` mail driver writes them to the container's output; read the verification and
  reset links with `docker compose logs app`.
- **Android emulator:** it reaches the machine at `10.0.2.2`: `http://10.0.2.2:8080/api/expenses`.
- **Physical device:** on the same network, at the machine's LAN address
  (`http://192.168.x.y:8080/api/expenses`). Under WSL2, Docker Desktop opens the port on the Windows
  host; if the phone cannot reach it, allow inbound port 8080 in the Windows firewall. Over USB, after
  `adb reverse tcp:8080 tcp:8080` the phone can use `http://localhost:8080` too.
- **Clean slate:** `docker compose down -v` deletes the development database as well.

## Working on the package

No PHP is needed on the host; every command runs in Docker (PHP 8.4 and MySQL 8.4):

```bash
docker compose run --rm php composer install
docker compose run --rm php composer quality            # Pint, PHPStan (level max), Pest
docker compose run --rm php composer test               # the tests, on MySQL
docker compose run --rm php composer test:performance   # the performance measurements
docker compose run --rm php composer analyse            # PHPStan / Larastan
docker compose run --rm php composer format             # Pint
```

The lowest supported PHP: `docker build --build-arg PHP_VERSION=8.3 docker/php`.

- The tests run on MySQL, never on SQLite. The tests that run DDL or examine concurrency use a
  throwaway database of their own.
- The performance targets (on the CI's machine): pushing 500 mixed records into an empty account
  < 1.5 s; pushing 500 unchanged records again < 0.5 s; a pull page of 1000 records < 0.5 s; a full
  pull of a 20 000-record account in pages of 1000 < 10 s. A separate CI job runs them; it informs
  but does not block.
- The CI runs on every push and pull request (PHP 8.3 `prefer-lowest`, 8.4, 8.5), and once a week
  with the newest dependencies the constraints allow.

## Versioning

- **The package's version is independent of the contract's.** `composer.json` requires the schema
  package as `^1.0.2`: the supported contract major is 1. The package speaks contract 1.2 and accepts
  clients on an older minor (such a client does not get the newer resources).
- **SemVer, starting at `0.x`:** until the client's sync is live, a `0.MINOR` bump may break. The first
  live client integration brings `1.0.0`; from then on only a major bump may break. What to do on a
  breaking change is in [UPGRADE.md](UPGRADE.md), the changes in [CHANGELOG.md](CHANGELOG.md) (both in
  Hungarian).
- **Releasing:**
  1. `composer quality` passes, the CI (the performance job included) is green;
  2. the `[Unreleased]` section of `CHANGELOG.md` gets the version number and the date;
  3. a git tag `vX.Y.Z` on `main`, and a GitHub release with the CHANGELOG section;
  4. a check in a fresh Laravel 13 app:
     `docker compose run --rm php sh bin/release-smoke.sh '^X.Y'`. The script plays the README's
     installation steps (the two VCS entries, `composer require`, the User model, the config,
     Sanctum, `migrate`), then runs a registration, a push and a pull. Without an argument it installs
     the working copy, so it can run before tagging too.

## License

MIT
