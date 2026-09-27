# laravel-expenses

Laravel 13 csomag: az Expenses mobilalkalmazás szinkron-backendje. A rekordok és a szinkronprotokoll
alakját a [`tamas-labs/expenses-schema`](https://github.com/tamas-labs/expenses-schema) szerződés
határozza meg, a csomag ezt valósítja meg a szerveren.

> **Állapot:** fejlesztés alatt. Még nincs kiadott verzió, és a felület változhat.

## Követelmények

- PHP 8.3 vagy újabb, Laravel 13
- MySQL 8.4 LTS vagy újabb (más adatbázis nem támogatott)

## Telepítés

Egyik csomag sincs a Packagist-on, mindkettő a GitHub repóból jön. A Composer a `repositories`
bejegyzést csak a gyökér `composer.json`-ból olvassa, ezért a host appnak **mindkét** repót fel kell
vennie:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/tamas-labs/laravel-expenses" },
    { "type": "vcs", "url": "https://github.com/tamas-labs/expenses-schema" }
]
```

```bash
composer require tamas-labs/laravel-expenses
php artisan vendor:publish --tag=expenses-config
```

A service providert az auto-discovery tölti be.

### Felhasználói modell

A csomag a host `users` táblájára épít, és a migrációja ehhez oszlopokat ad (`uuid`,
`default_currency_id`, `registered_at`, `profile_created_at`, `profile_updated_at`, `server_seq`).
Ha ezek közül valamelyik már létezik, a migráció érthető hibával leáll. A User modellre kerüljön a
trait:

```php
use TamasLabs\LaravelExpenses\HasExpensesProfile;

class User extends Authenticatable
{
    use HasExpensesProfile;
}
```

Ha a modell nem `App\Models\User`, az `EXPENSES_USER_MODEL` környezeti változó (vagy az
`expenses.user_model` config) adja meg.

### Migrációk

```bash
php artisan migrate
```

A csomag migrációi publikálás nélkül is lefutnak. Ha a host táblanevei ütköznének a csomagéival
(`categories`, `expenses`, …), az `EXPENSES_TABLE_PREFIX` (legfeljebb 7 karakter, pl. `exp_`) minden
csomagtábla elé prefixet tesz. **Az első migrálás előtt kell beállítani**, utólag nem módosítható.

Testre szabáshoz a migrációk publikálhatók (`--tag=expenses-migrations`). A másolat az eredeti
fájlnevekkel kerül a `database/migrations` könyvtárba, és a csomagé helyett fut, nem mellette.

## Szinkron végpontok

A csomag két route-ot regisztrál az `expenses.routes.prefix` (alapértelmezés: `api/expenses`) alatt:

| Végpont                                 | Név                  | Törzs / válasz                    |
| --------------------------------------- | -------------------- | --------------------------------- |
| `POST …/sync/push`                      | `expenses.sync.push` | `push-request` → `push-response`  |
| `GET …/sync/pull?cursor=<c>&limit=<n>`  | `expenses.sync.pull` | — → `pull-response`               |

Az alakokat és a protokoll szabályait (LWW, cursor, hibakódok) a
[`tamas-labs/expenses-schema`](https://github.com/tamas-labs/expenses-schema) README-je írja le.
Mindkét route hitelesített felhasználót kér, és megkapja az `expenses.routes.middleware`
middleware-eit (alapértelmezés: `api`, `expenses.contract`). Az `expenses.contract` maradjon a
listában: ez egyezteti a kliens szerződésverzióját. A korlátok az `expenses.sync` alatt
állíthatók: legfeljebb 500 rekord egy push-ban, a pull lapmérete alapértelmezésben 500, legfeljebb
1000.

Ha egy push az adatbázisba is írt, a commit után a csomag
`TamasLabs\LaravelExpenses\Sync\Events\RecordsPushed` eseményt küld: benne a felhasználó és az
erőforrásonként írt rekordok száma. A host app figyelhet rá, a csomag maga nem használja.

## Fejlesztés

Minden parancs Dockerben fut (PHP 8.4 és MySQL 8.4):

```bash
docker compose run --rm php composer install
docker compose run --rm php composer quality   # Pint, PHPStan (level max), Pest
```

Külön is futtathatók: `composer test`, `composer analyse`, `composer format`, `composer format:check`.

## Licenc

MIT
