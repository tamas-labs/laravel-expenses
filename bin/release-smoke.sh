#!/bin/sh
# Release smoke test (spec 08, 3.11/4): a fresh Laravel 13 application
# installs the package the way the README says, migrates, and a client
# registers, pushes and pulls against it.
#
#   docker compose run --rm php sh bin/release-smoke.sh            # this working copy
#   docker compose run --rm php sh bin/release-smoke.sh '^0.1'     # a released tag, from GitHub
#
# Needs the network (Packagist, GitHub) and the compose MySQL service; the
# application and its database are thrown away at the end.
set -eu

CONSTRAINT="${1:-}"
PACKAGE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
WORK_DIR="$(mktemp -d /tmp/expenses-smoke.XXXXXX)"
DB_HOST="${DB_HOST:-mysql}"
DB_PASSWORD="${DB_PASSWORD:-secret}"
DB_DATABASE=expenses_smoke
PORT=8099
SERVER_PID=

cleanup() {
    if [ -n "$SERVER_PID" ]; then kill "$SERVER_PID" 2>/dev/null || true; fi
    rm -rf "$WORK_DIR"
}
trap cleanup EXIT

step() { printf '\n==> %s\n' "$*"; }

step "A fresh Laravel 13 application in $WORK_DIR"
composer create-project laravel/laravel:^13.0 "$WORK_DIR/app" --no-interaction --no-progress --quiet
cd "$WORK_DIR/app"

step 'The two VCS repositories (README: Telepítés)'
if [ -z "$CONSTRAINT" ]; then
    composer config repositories.laravel-expenses "{\"type\": \"path\", \"url\": \"$PACKAGE_DIR\", \"options\": {\"symlink\": false}}"
    CONSTRAINT='*@dev'
else
    composer config repositories.laravel-expenses vcs https://github.com/tamas-labs/laravel-expenses
fi
composer config repositories.expenses-schema vcs https://github.com/tamas-labs/expenses-schema

step "composer require tamas-labs/laravel-expenses:$CONSTRAINT"
composer require "tamas-labs/laravel-expenses:$CONSTRAINT" --no-interaction --no-progress

step 'The environment: MySQL, the mail links, mails to the log'
export SMOKE_DB_HOST="$DB_HOST" SMOKE_DB_DATABASE="$DB_DATABASE" SMOKE_DB_PASSWORD="$DB_PASSWORD"
php -r '
$env = file_get_contents(".env");
$settings = [
    "DB_CONNECTION" => "mysql",
    "DB_HOST" => getenv("SMOKE_DB_HOST"),
    "DB_PORT" => "3306",
    "DB_DATABASE" => getenv("SMOKE_DB_DATABASE"),
    "DB_USERNAME" => "root",
    "DB_PASSWORD" => getenv("SMOKE_DB_PASSWORD"),
    "MAIL_MAILER" => "log",
    "EXPENSES_PASSWORD_RESET_URL" => "\"expenses://reset-password?token={token}&email={email}\"",
    "EXPENSES_EMAIL_VERIFIED_URL" => "https://example.com/email-verified",
];
foreach ($settings as $key => $value) {
    $pattern = "/^#?\\s*".$key."=.*$/m";
    $env = preg_match($pattern, $env) === 1
        ? preg_replace_callback($pattern, static fn (): string => $key."=".$value, $env, 1)
        : rtrim($env)."\n".$key."=".$value."\n";
}
file_put_contents(".env", $env);
$pdo = new PDO("mysql:host=".getenv("SMOKE_DB_HOST"), "root", getenv("SMOKE_DB_PASSWORD"));
$pdo->exec("DROP DATABASE IF EXISTS `".getenv("SMOKE_DB_DATABASE")."`");
$pdo->exec("CREATE DATABASE `".getenv("SMOKE_DB_DATABASE")."`");
'

step 'The user model (README: Felhasználói modell)'
php -r '
$file = "app/Models/User.php";
$source = file_get_contents($file);
$source = preg_replace("/^class User extends Authenticatable$/m", "class User extends Authenticatable implements \\Illuminate\\Contracts\\Auth\\MustVerifyEmail", $source, 1, $classes);
$source = preg_replace("/^(\s*)use ([A-Za-z, ]*Notifiable[A-Za-z, ]*);$/m", "$1use $2, \\Laravel\\Sanctum\\HasApiTokens, \\TamasLabs\\LaravelExpenses\\HasExpensesProfile;", $source, 1, $traits);
if ($classes !== 1 || $traits !== 1) {
    fwrite(STDERR, "The skeleton'"'"'s User model has changed; update bin/release-smoke.sh.\n");
    exit(1);
}
file_put_contents($file, $source);
'

step 'The config and the Sanctum token table (README: Telepítés)'
php artisan vendor:publish --tag=expenses-config --no-interaction
php artisan install:api --without-migration-prompt --no-interaction

step 'php artisan migrate'
php artisan migrate --force --no-interaction

step "The server on port $PORT"
php artisan serve --host=127.0.0.1 --port="$PORT" > "$WORK_DIR/server.log" 2>&1 &
SERVER_PID=$!

step 'A client: register, push, pull'
SMOKE_URL="http://127.0.0.1:$PORT/api/expenses" php -r '
$base = getenv("SMOKE_URL");

function call(string $method, string $url, ?array $body = null, ?string $token = null): array
{
    $headers = ["Content-Type: application/json", "Accept: application/json", "X-Expenses-Contract: 1.2"];
    if ($token !== null) {
        $headers[] = "Authorization: Bearer ".$token;
    }
    $context = stream_context_create(["http" => [
        "method" => $method,
        "header" => implode("\r\n", $headers),
        "content" => $body === null ? "" : json_encode($body),
        "ignore_errors" => true,
        "timeout" => 30,
    ]]);
    $response = @file_get_contents($url, false, $context);
    $status = (int) explode(" ", $http_response_header[0] ?? "HTTP/1.1 0")[1];

    return [$status, json_decode((string) $response, true)];
}

function check(bool $condition, string $what, mixed $answer): void
{
    if (! $condition) {
        fwrite(STDERR, "FAILED: ".$what."\n".json_encode($answer, JSON_PRETTY_PRINT)."\n");
        exit(1);
    }
    echo "ok: ", $what, "\n";
}

for ($attempt = 0; @file_get_contents(substr($base, 0, strpos($base, "/api"))) === false; $attempt++) {
    check($attempt < 50, "the server starts", null);
    usleep(200000);
}

[$status, $answer] = call("POST", $base."/auth/register", [
    "email" => "smoke@example.com",
    "password" => "correct horse battery",
    "displayName" => "Smoke",
    "deviceId" => "3f1c2e4d-5a6b-4c7d-8e9f-0a1b2c3d4e5f",
    "defaultCurrencyId" => "00000000-0000-4000-8000-000000000001",
]);
check($status === 201 && isset($answer["tokens"]["accessToken"]), "register", $answer);
$token = $answer["tokens"]["accessToken"];

$category = [
    "id" => "1b9d6bcd-bbfd-4b2d-9b5d-ab8dfbbd4bed", "name" => "Élelmiszer", "sortOrder" => 0, "color" => null, "icon" => null,
    "createdAt" => "2026-03-01T10:00:00.000Z", "updatedAt" => "2026-03-01T10:00:00.000Z", "deletedAt" => null,
];
[$status, $answer] = call("POST", $base."/sync/push", ["records" => ["category" => [$category]]], $token);
check($status === 200 && ($answer["results"]["category"][0]["status"] ?? null) === "accepted", "push", $answer);

[$status, $answer] = call("GET", $base."/sync/pull", null, $token);
check($status === 200 && ($answer["records"]["category"][0] ?? null) === $category, "pull", $answer);
check(($answer["records"]["user"][0]["email"] ?? null) === "smoke@example.com" && count($answer["records"]["currency"] ?? []) === 3, "pull: the account and the currencies", $answer);
'

step 'Dropping the database'
php -r '(new PDO("mysql:host=".getenv("SMOKE_DB_HOST"), "root", getenv("SMOKE_DB_PASSWORD")))->exec("DROP DATABASE `".getenv("SMOKE_DB_DATABASE")."`");'

printf '\nThe release smoke test passed.\n'
