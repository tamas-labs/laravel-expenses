# tamas-labs/laravel-expenses

**Nyelv:** Magyar · [English](README.en.md)

Laravel 13 csomag: az Expenses mobilalkalmazás (React Native) szinkron-backendje. Regisztrációt,
bejelentkezést és tokenes hitelesítést ad a kliensnek, tárolja a felhasználónkénti rekordokat, és a
kliens változásait a szerződés szerint fogadja (push) és adja vissza (pull).

A rekordok és a szinkronprotokoll alakját a
[`tamas-labs/expenses-schema`](https://github.com/tamas-labs/expenses-schema) szerződés határozza meg.
**A szerződés az igazság forrása:** a csomag nem definiál saját wire formátumot, minden kimenő
payloadja megfelel a sémának.

## Tartalom

1. [Mi ez, és mi nem](#mi-ez-és-mi-nem)
2. [Követelmények](#követelmények)
3. [Telepítés](#telepítés)
4. [Üzemeltetés](#üzemeltetés)
5. [Az API](#az-api)
6. [Szinkronszabályok a kliensfejlesztőnek](#szinkronszabályok-a-kliensfejlesztőnek)
7. [Fejlesztői szerver](#fejlesztői-szerver)
8. [Fejlesztés a csomagon](#fejlesztés-a-csomagon)
9. [Verziózás](#verziózás)

## Mi ez, és mi nem

**A csomag adja:**

- a kliens végpontjait: regisztráció, bejelentkezés, tokenfrissítés, kijelentkezés,
  jelszó-visszaállítás, e-mail-megerősítés, profil (`/me`), fióktörlés, push és pull;
- a szinkronizált erőforrások tábláit (migrációk), a host `users` táblájának bővítését;
- a szerződés ellenőrzését (JSON Schema), a domain szabályokat, a last-write-wins döntést és a
  globális sorszámra épülő pullt;
- a védelmet (rate limit, body-méret), a karbantartó parancsokat és a naplózást.

**A host app dolga** (a csomag ehhez dokumentációt és szolgáltatást ad, de nem valósítja meg):

- a web szerver, a PHP és a MySQL üzemeltetése, a HTTPS;
- a levélküldés beállítása (a csomag a Laravel `mail` beállításával küld);
- az ütemező futtatása (a csomag nem ütemez magától, lásd [Ütemezés](#ütemezés));
- a webes fióktörlési oldal, amelyet a Google Play megkövetel (lásd [Fióktörlés](#fióktörlés));
- a jelszó-visszaállító és a megerősítés utáni oldal vagy app deep link.

Webes felület, admin panel és riportok nincsenek. A felhasználók adatai egymástól teljesen
elkülönülnek, közös (megosztott) adat nincs.

## Követelmények

- PHP 8.3, 8.4 vagy 8.5, `pdo_mysql` kiterjesztéssel
- Laravel 13
- MySQL 8.4 LTS vagy újabb. Más adatbázis (MariaDB, PostgreSQL, SQLite) nem támogatott: a csomag
  generált oszlopokra és összetett idegen kulcsokra épít.
- [Laravel Sanctum](https://laravel.com/docs/sanctum) 4.3.1 vagy újabb (a csomag függősége)

## Telepítés

### 1. A két VCS repository

Egyik csomag sincs a Packagist-on, mindkettő a GitHub repóból jön, a verzió git tag. A Composer a
`repositories` bejegyzést csak a gyökér `composer.json`-ból olvassa, ezért a host appnak **mindkét**
repót fel kell vennie:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/tamas-labs/laravel-expenses" },
    { "type": "vcs", "url": "https://github.com/tamas-labs/expenses-schema" }
]
```

Mindkét repó nyilvános, GitHub token nem kell.

### 2. A csomag

```bash
composer require tamas-labs/laravel-expenses:^0.1
```

A service providert az auto-discovery tölti be.

### 3. A User modell

A csomag a host `users` táblájára épít. A User modellnek két traitet kell használnia és a
`MustVerifyEmail` interfészt implementálnia. A csomag induláskor ellenőrzi, és érthető hibát ad, ha
valamelyik hiányzik:

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

Ha a modell nem `App\Models\User`, az `EXPENSES_USER_MODEL` környezeti változó (vagy az
`expenses.user_model` config) adja meg. A jelszó-visszaállítás a Laravel alapértelmezett password
brokerén megy, ezért az `auth.providers.users.model` is ugyanez a modell legyen.

A migráció a `users` táblához oszlopokat ad (`uuid`, `default_currency_id`, `registered_at`,
`profile_created_at`, `profile_updated_at`, `server_seq`). Ha ezek közül valamelyik már létezik, a
migráció érthető hibával leáll. A regisztráció a `name`, `email`, `password` és a profiloszlopok
kitöltésével hoz létre felhasználót (`forceFill`, a `$fillable` nem számít). **Ha a host `users`
táblájának más kötelező oszlopa is van, annak alapértéket kell adni**, különben a regisztráció
adatbázishibával elbukik.

### 4. A két kötelező környezeti változó

```dotenv
# A jelszó-visszaállító levél linkje: app deep link vagy a host oldala, {token} és {email} helyőrzővel
EXPENSES_PASSWORD_RESET_URL="expenses://reset-password?token={token}&email={email}"
# Ide vezet a megerősítő link megnyitása; érvénytelen linknél ?status=invalid kerül a végére
EXPENSES_EMAIL_VERIFIED_URL="https://example.com/email-verified"
```

Nélkülük (és a 3. pont nélkül) a csomag induláskor érthető hibával leáll. A csomagfelderítés (a
Composer által futtatott `package:discover`) és a `vendor:publish` ezek beállítása előtt is működik.

### 5. A config, a Sanctum tokentáblája és a migrációk

```bash
php artisan vendor:publish --tag=expenses-config   # opcionális: config/expenses.php
php artisan install:api                            # a Sanctum personal_access_tokens táblája, ha még nincs
php artisan migrate
```

A Sanctum tokentáblájának migrációja a hosté: az `install:api` vagy a
`vendor:publish --tag=sanctum-migrations` hozza létre. A csomag migrációi publikálás nélkül is
lefutnak. Ha a host táblanevei ütköznének a csomagéival (`categories`, `expenses`, …), az
`EXPENSES_TABLE_PREFIX` (legfeljebb 7 karakter, pl. `exp_`) minden csomagtábla elé prefixet tesz.
**Az első migrálás előtt kell beállítani**, utólag nem módosítható. Testre szabáshoz a migrációk
publikálhatók (`--tag=expenses-migrations`): a másolat az eredeti fájlnevekkel a
`database/migrations` könyvtárba kerül, és a csomagé helyett fut, nem mellette.

### 6. Levélküldés

A regisztráció megerősítő levelet, a jelszó-visszaállítás visszaállító levelet küld, a Laravel
`MustVerifyEmail`, `VerifyEmail` és `ResetPassword` eszközeivel, a host `mail` beállításával. A
levelek linkjét a csomag a `VerifyEmail::createUrlUsing()` és a `ResetPassword::createUrlUsing()`
hívással állítja be, **de csak akkor, ha a host nem tette meg** (egy service provider `boot()`-jában).
A levél nyelve a kérés `Accept-Language` fejlécéből jön (`hu` vagy `en`, egyébként az app
alapértelmezése). A csomag magyar fordítást szállít a Laravel alapértelmezett szövegeihez, a host
`lang/hu.json`-ja felülírhatja. A küldés hibája naplózódik, a regisztráció ettől még sikeres.

### Beállítások

Minden kulcs a `config/expenses.php`-ben, megjegyzéssel:

| Kulcs                                  | Alapérték           | Mire való                                                    |
| -------------------------------------- | ------------------- | ------------------------------------------------------------ |
| `user_model`                           | `App\Models\User`   | a host User modellje (`EXPENSES_USER_MODEL`)                 |
| `database.table_prefix`                | `''`                | a csomagtáblák prefixe (`EXPENSES_TABLE_PREFIX`)             |
| `routes.prefix`                        | `api/expenses`      | a végpontok URI-prefixe (`EXPENSES_ROUTE_PREFIX`)            |
| `routes.middleware`                    | `api`, `expenses.contract` | a végpontok middleware-jei; az `expenses.contract` maradjon |
| `auth.access_ttl` / `auth.refresh_ttl` | 60 perc / 90 nap    | a tokenek élettartama                                        |
| `auth.password_reset_url`              | —                   | kötelező, lásd fent                                          |
| `auth.email_verified_url`              | —                   | kötelező, lásd fent                                          |
| `auth.require_verified_email`          | `false`             | a szinkron megerősített e-mailt kíván-e                      |
| `sync.push_max_records`                | 500                 | egy push legfeljebb ennyi rekord                             |
| `sync.pull_default_limit` / `pull_max_limit` | 500 / 1000    | a pull lapmérete                                             |
| `rate_limits.push` / `pull` / `me`     | 30 / 120 / 30       | kérés percenként és felhasználónként                         |
| `http.max_body_kb`                     | 4096                | a legnagyobb kéréstörzs                                      |
| `pruning.tombstone_days`               | 180                 | a tombstone-ok megőrzési ideje                               |
| `logging.channel`                      | a host alapértelmezése | a csomag naplócsatornája (`EXPENSES_LOG_CHANNEL`)         |

## Üzemeltetés

### Ütemezés

A csomag nem ütemez magától, a host ütemezőjébe (`routes/console.php`) javasolt bejegyzések:

```php
use Illuminate\Support\Facades\Schedule;
use TamasLabs\LaravelExpenses\Auth\RefreshToken;

Schedule::command('expenses:prune-tombstones')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [RefreshToken::class]])->daily();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('auth:clear-resets')->everyFifteenMinutes();
```

- **`expenses:prune-tombstones`**: véglegesen törli azokat a tombstone-okat (törölt rekordokat),
  amelyeket a szerver több mint 180 napja tárolt (`expenses.pruning.tombstone_days`, a `--days`
  opcióval felülírható), és amelyekre semmi nem hivatkozik. A szerver ideje számít, nem a kliens
  `deletedAt`-je. A gyerekek a szüleik előtt mennek, így egy futás egy egész láncot törölhet. Ezres
  kötegekben, külön tranzakciókban dolgozik, a futás végén egy naplósort ír. A `--dry-run` csak
  megszámolja, mit törölne. **Következmény:** egy kliens, amelynek cursora a törölt tombstone-ok
  előttről való, `410 cursor_expired`-et kap, és teljes újraletöltést végez. Ez a gyakorlatban egy
  180 napnál régebben szinkronizált eszköz.
- **`model:prune`** a `RefreshToken` modellel: a lejárat után 7 nappal törli a refresh tokeneket
  (addig az elhasznált token is megmarad, hogy az újrafelhasználása lebuktassa a lopást).
- **`sanctum:prune-expired`**: a lejárt access tokenek (frissítésenként egy keletkezik).
- **`auth:clear-resets`**: a lejárt jelszó-visszaállító tokenek.

### Web szerver és PHP korlátok

A csomag 4096 KB-nál nagyobb kéréstörzst `413 payload_too_large`-dzsel utasít el
(`expenses.http.max_body_kb`). Hogy ne a web szerver vagy a PHP vágjon előbb, érthetetlen hibával, a
korlátjuk legyen nagyobb:

```nginx
client_max_body_size 8m;
```

```ini
post_max_size = 8M
```

Egy 500 rekordos push (a kiadások tételeivel és a termékek `offData`-jával) kényelmesen belefér a
4 MB-ba.

### Rate limit

A szinkron és a profil végpontjai felhasználónként, percenként korlátozottak: push 30, pull 120,
`GET`/`PATCH`/`DELETE /me` együtt 30 (`expenses.rate_limits.*`). A pull korlátja azért magasabb, mert
egy első letöltés sok oldalt kér egymás után. A korlát felett `429 too_many_requests` jön
`Retry-After` fejléccel. A limiterek nevesítettek (`expenses-push`, `expenses-pull`,
`expenses-me`), a host felülírhatja őket:

```php
RateLimiter::for('expenses-pull', fn (Request $request) => Limit::perMinute(300)->by($request->user()?->getAuthIdentifier()));
```

A fiókvégpontok saját, rögzített fékkel rendelkeznek: percenként 5 sikertelen bejelentkezés
`(e-mail, IP)` páronként és 20 IP-nként, óránként 10 regisztráció és 5 visszaállító levél IP-nként,
3 megerősítő levél felhasználónként, és percenként 5 hibás jelszó a fióktörlésnél felhasználónként
(`429 too_many_attempts`).

**Reverse proxy mögött** (load balancer, Cloudflare, nginx egy másik gépen) a Laravel trusted proxies
beállítása (`bootstrap/app.php`: `$middleware->trustProxies(...)`) kötelező. Nélküle minden kliens a
proxy IP-jéről jönne, így az IP-alapú fékek mindenkire együtt vonatkoznának, és a megerősítő link
aláírása a `https` és a host eltérése miatt érvénytelen lenne.

### Naplózás

- **Kérésazonosító:** a csomag minden route-ja átveszi a kliens `X-Request-Id` fejlécét, ha érvényes
  UUID, különben generál egyet. A válaszon visszaküldi, és a Laravel Contexten át minden naplósorba
  beteszi (`requestId`), bármelyik csatornán. Így egy kliensoldali hiba a szerver naplójában
  megtalálható.
- **Push-összesítő:** minden push után egy `info` sor (`Expenses push.`): a felhasználó UUID-ja, az
  eszköz, erőforrásonként és státuszonként a darabszám, az időtartam. Ha van `rejected` rekord, a sor
  `warning`, és a rekordok azonosítóját és a hibák `path` + `keyword` párjait is tartalmazza. Az
  egészében elutasított push is `warning` sort kap a hibakóddal.
- **Soha nem kerül naplóba:** rekordtartalom (nevek, összegek, jegyzetek), jelszó, token. A csomag a
  `password`, `token`, `accessToken` és `refreshToken` mezőt felveszi a kivételkezelő `dontFlash`
  listájára, és a jelszót és a tokeneket kezelő paramétereket `#[SensitiveParameter]`-rel jelöli.
  Sikertelen bejelentkezéskor csak az e-mail SHA-256 hash-e és az IP kerül a naplóba.
- **Csatorna:** `expenses.logging.channel` (`EXPENSES_LOG_CHANNEL`), alapértelmezésben a host
  alapértelmezett csatornája.
- **Váratlan hiba:** a kivétel a host kivételkezelőjéhez kerül (naplózás, hibakövető), a kliens
  `500 server_error`-t kap a kérésazonosítóval, belső részletek nélkül, `APP_DEBUG` mellett is.

### Octane

A csomag szolgáltatásai állapotmentesek, vagy csak lefordított sémát tartanak, ezért Octane alatt is
biztonságosak. A kérésazonosító a Laravel Contextben él, amelyet az Octane kérésenként ürít.

### Fióktörlés

A `DELETE …/me` a jelszó újbóli megadása után egy tranzakcióban törli a fiók minden adatát, eszközét
és tokenjét, végül a `users` sort. A Google Play a webes törlést is megköveteli: ez a host dolga, egy
egyszerű oldallal, amely a felhasználó azonosítása után ugyanezt a szolgáltatást hívja:

```php
app(\TamasLabs\LaravelExpenses\Auth\DeleteAccount::class)($user);
```

### Események

A host app figyelhet rájuk, a csomag maga nem használja őket:

- `TamasLabs\LaravelExpenses\Sync\Events\RecordsPushed`: egy push az adatbázisba is írt (a commit
  után). Benne a felhasználó és az erőforrásonként írt rekordok száma.
- `TamasLabs\LaravelExpenses\Auth\Events\AccountDeleted`: egy fiók törlődött (a commit után). Benne
  a felhasználó szerződésbeli `uuid`-ja.
- A Laravel saját `Verified` és `PasswordReset` eseménye a megerősítéskor és a jelszó cseréjekor.

## Az API

### Végpontok

A route-ok az `expenses.routes.prefix` (alapértelmezés: `api/expenses`) alatt vannak. A kérés- és
válaszalakok a schema repó [`schema/protocol/`](https://github.com/tamas-labs/expenses-schema/tree/main/schema/protocol)
dokumentumai.

| Végpont                                  | Hitelesítés  | Törzs → válasz                                  | Rate limit |
| ---------------------------------------- | ------------ | ----------------------------------------------- | ---------- |
| `POST …/auth/register`                   | —            | `register-request` → 201 `auth-response`        | 10/óra/IP  |
| `POST …/auth/login`                      | —            | `login-request` → `auth-response`               | 5 hiba/perc |
| `POST …/auth/refresh`                    | —            | `refresh-request` → `refresh-response`          | —          |
| `POST …/auth/logout`                     | access token | — → 204                                         | —          |
| `POST …/auth/password/forgot`            | —            | `password-forgot-request` → 202                 | 5/óra/IP   |
| `POST …/auth/password/reset`             | —            | `password-reset-request` → 204                  | —          |
| `GET …/auth/email/verify/{uuid}/{hash}`  | aláírt link  | — → átirányítás                                 | —          |
| `POST …/auth/email/resend`               | access token | — → 202                                         | 3/óra      |
| `GET …/me`                               | access token | — → `me-response`                               | 30/perc    |
| `PATCH …/me`                             | access token | a teljes `user` rekord → `me-update-result`     | 30/perc    |
| `DELETE …/me`                            | access token | `account-delete-request` → 204                  | 30/perc    |
| `POST …/sync/push`                       | access token | `push-request` → `push-response`                | 30/perc    |
| `GET …/sync/pull?cursor=<c>&limit=<n>`   | access token | — → `pull-response`                             | 120/perc   |

A route-nevek `expenses.` előtagúak (pl. `expenses.sync.push`). Minden route megkapja az
`expenses.routes.middleware` middleware-eit, kivéve a megerősítő linket, amelyet a felhasználó
böngészőben nyit meg.

### Fejlécek

| Fejléc                | Irány          | Jelentés                                                                 |
| --------------------- | -------------- | ------------------------------------------------------------------------ |
| `X-Expenses-Contract` | kérés, válasz  | a szerződés verziója (`<major>.<minor>`). Kötelező minden kérésen, a válasz a szerverét hozza. Eltérő major vagy újabb minor: 400. |
| `Authorization`       | kérés          | `Bearer <access token>` a védett végpontokon                             |
| `X-Request-Id`        | kérés, válasz  | a kérés naplóazonosítója (UUID); a kliens küldheti, a szerver mindig visszaküldi |
| `Accept-Language`     | kérés          | a levelek nyelve (`hu`, `en`)                                            |
| `Retry-After`         | válasz         | 429 esetén: ennyi másodperc múlva jöhet a következő kérés                |

### Hitelesítés

- **Access token:** Sanctum personal access token `expenses:access` képességgel, eszközönként
  (`device:<deviceId>` néven), 60 percig érvényes. Token nélkül 401 `unauthenticated`, a képesség
  nélküli tokenre 403 `forbidden` jön.
- **Refresh token:** 90 napig érvényes, csak a `/auth/refresh` törzsében utazik. Minden frissítés
  elhasználja és új párt ad, így a lejárat csúszó. Egy elhasznált refresh token ismételt bemutatása
  lopásra utal: a tokenlánc és az eszköz access tokenjei visszavonódnak (401 `refresh_token_reused`).
  A szerver csak a SHA-256 hash-ét tárolja.
- **Eszközök:** egy eszköz (`deviceId`) egyszerre egy fiókhoz tartozik. Egy másik fiókba belépve az
  eszköz átkerül, és a régi fiók tokenjei rajta visszavonódnak. A kijelentkezés az eszköz tokenjeit
  visszavonja, és leválasztja az eszközt.
- **Bejelentkezés:** minden hiba ugyanaz a 401 `invalid_credentials`, nem létező fióknál is lefut egy
  hash-ellenőrzés.
- **Jelszó:** legalább 8 karakter, bcrypt mellett legfeljebb 72 bájt, más hash-elővel legfeljebb 256
  karakter. A jelszó-visszaállítás a fiók minden tokenjét és eszközét visszavonja.
- **E-mail-megerősítés:** alapból nem feltétele a szinkronnak. Az
  `expenses.auth.require_verified_email` bekapcsolásával a szinkron és a profilmódosítás 403
  `email_not_verified`-et ad megerősítés előtt.

### Hibakódok

Minden boríték-szintű hiba a `protocol/error` alakú: `{"error": {"code": …, "message": …}}`, ahol
kell, `resource`, `issues`, `serverVersion` vagy `requestId` mezővel. A kliens a `code` alapján dönt,
a `message` csak tájékoztató. A rekordszintű push-hibák nem ezt használják, hanem a `rejected`
eredményt. A kódok jelentését a schema repó
[„Ismert hibakódok”](https://github.com/tamas-labs/expenses-schema/blob/main/README.hu.md#hibaboríték-és-ismert-hibakódok)
táblázata írja le.

| Státusz | Kód                                                      | Mikor                                                    |
| ------- | -------------------------------------------------------- | -------------------------------------------------------- |
| 400     | `contract_version_missing`, `_malformed`, `_unsupported` | az `X-Expenses-Contract` hiányzik, hibás vagy nem támogatott |
| 401     | `unauthenticated`                                        | nincs, lejárt vagy érvénytelen access token              |
| 401     | `invalid_credentials`                                    | hibás e-mail vagy jelszó                                 |
| 401     | `refresh_token_invalid`, `refresh_token_reused`          | a frissítés hibái                                        |
| 403     | `forbidden`, `email_not_verified`                        | a token nem jogosít; nincs megerősítve az e-mail         |
| 410     | `cursor_expired`                                         | a cursor régebbi a törölt tombstone-oknál: teljes újraletöltés |
| 413     | `payload_too_large`                                      | a kéréstörzs nagyobb a korlátnál                          |
| 422     | `contract_violation`                                     | a kérés nem felel meg a sémának (`issues`)               |
| 422     | `validation_failed`, `field_not_writable`, `email_taken`, `reset_token_invalid` | a fiókvégpontok hibái |
| 422     | `resource_not_writable`, `duplicate_record`, `too_many_records`, `cursor_malformed` | a push és a pull hibái |
| 429     | `too_many_requests`, `too_many_attempts`                 | rate limit, illetve a fiókvégpontok fékje (`Retry-After`) |
| 500     | `server_error`                                           | váratlan hiba; a `requestId`-vel a szerver naplójában megtalálható |

## Szinkronszabályok a kliensfejlesztőnek

A protokoll teljes leírása a schema repó
[„Szinkronprotokoll”](https://github.com/tamas-labs/expenses-schema/blob/main/README.hu.md#szinkronprotokoll)
fejezete. A kliens kötelezettségei:

1. **Minden kérésen `X-Expenses-Contract`.**
2. **Push és pull sorrendje szabad.** A saját elfogadott rekordjai a következő pullban visszajönnek
   (új sorszámmal). Ez ártalmatlan, helyben no-op.
3. **`stale` → a válasz `record`-ja felülírja a helyi változatot**, és a rekord tiszta lesz. A szerver
   változata nyert, és a kliens cursora már túl lehet rajta.
4. **`rejected` → a rekord piszkos marad**, a kliens nem próbálja újra változatlanul végtelen
   ciklusban: a hiba adatjavítást igényel.
5. **`unique` hiba `conflictingId`-vel → az összefésülés** szerint a helyi rekord `id`-jét a szerveré
   váltja.
6. **A pull oldalait egy tranzakcióban alkalmazza**, halasztott FK-ellenőrzéssel
   (`PRAGMA defer_foreign_keys = ON`), amíg a `hasMore` `false` nem lesz: egy oldal gyereket hozhat
   olyan szülő nélkül, amely a következő oldalon jön.
7. **Pull-kor a LWW-t ugyanígy alkalmazza:** a későbbi `updatedAt` nyer, **döntetlennél a szerver
   változata marad**.
8. **`410 cursor_expired` → teljes újraletöltés** cursor nélkül, de előtte a helyi, még fel nem
   töltött változások pusholása.
9. **A cursor átlátszatlan:** a kliens csak tárolja és visszaküldi.
10. **`429` → a `Retry-After` kivárása**, nem azonnali ismétlés. Egy push legfeljebb 500 rekord, a
    nagyobb változáshalmaz több push.
11. **Hibabejelentéshez az `X-Request-Id`:** a kliens küldhet sajátot, vagy elteheti a válaszét.

## Fejlesztői szerver

A React Native kliens szinkronjához egy futó szerver kell. A repó ezt a Testbench Workbench-csel adja:

```bash
docker compose up app
```

Ez elindítja a MySQL-t (`dev-mysql`, kötetben tárolva, így újraindítás után is megmarad), lefuttatja a
migrációkat és a `composer dev:seed`-et, és a `8080`-as porton kiszolgálja az API-t:
`http://localhost:8080/api/expenses`.

- **Tesztfelhasználó:** `dev@example.com` / `password`, megerősített e-mail-címmel. Mellette a kliens
  seed kategóriái, alkategóriái és fizetési módjai, a rögzített seed UUID-kkal
  (`00000000-0000-4000-8100-…`, `…-8200-…`, `…-8300-…`), régebbi időbélyeggel, mint egy eszköz
  saját seedje. Így a kliens összefésülési folyamata is kipróbálható. A seed újrafuttatható.
- **Levelek:** a `log` mail driverrel a konténer kimenetére mennek. A megerősítő és a visszaállító link
  innen olvasható ki: `docker compose logs app`.
- **Android emulátor:** a gépet a `10.0.2.2` címen éri el: `http://10.0.2.2:8080/api/expenses`.
- **Fizikai eszköz:** ugyanazon a hálózaton a gép LAN-címén (`http://192.168.x.y:8080/api/expenses`).
  WSL2 alatt a Docker Desktop a Windows gépen nyitja meg a portot; ha a telefon nem éri el, a Windows
  tűzfalán engedélyezni kell a 8080-as bejövő portot. USB-n `adb reverse tcp:8080 tcp:8080` után a
  telefonon is `http://localhost:8080` használható.
- **Tiszta lap:** `docker compose down -v` törli a fejlesztői adatbázist is.

## Fejlesztés a csomagon

A hoston nem kell PHP, minden parancs Dockerben fut (PHP 8.4 és MySQL 8.4):

```bash
docker compose run --rm php composer install
docker compose run --rm php composer quality            # Pint, PHPStan (level max), Pest
docker compose run --rm php composer test               # a tesztek, MySQL-en
docker compose run --rm php composer test:performance   # a teljesítménymérések
docker compose run --rm php composer analyse            # PHPStan / Larastan
docker compose run --rm php composer format             # Pint
```

A legalacsonyabb támogatott PHP: `docker build --build-arg PHP_VERSION=8.3 docker/php`.

- A tesztek MySQL-en futnak, SQLite-on soha. A DDL-t futtató és a párhuzamosságot vizsgáló tesztek
  saját, eldobható adatbázist használnak.
- A teljesítménymérések célértékei (a CI gépén): 500 vegyes rekord pusha üres fiókba < 1,5 s; 500
  változatlan rekord ismételt pusha < 0,5 s; egy 1000 rekordos pull-oldal < 0,5 s; egy 20 000 rekordos
  fiók teljes pullja 1000-es oldalakkal < 10 s. A CI-ban külön job futtatja, amely tájékoztat, de
  nem blokkol.
- A CI minden pushra és pull requestre fut (PHP 8.3 `prefer-lowest`, 8.4, 8.5), és hetente egyszer a
  legfrissebb engedett függőségekkel is.

## Verziózás

- **A csomag verziója független a szerződés verziójától.** A `composer.json` a schema csomagra
  `^1.0.2` kötést ad: a támogatott szerződés-major az 1-es. A csomag 1.2-es szerződést beszél, és
  elfogadja a régebbi minor verziójú klienst (az újabb erőforrásokat az ilyen kliens nem kapja meg).
- **SemVer, `0.x`-szel indulva:** amíg a kliens szinkronja nem éles, a `0.MINOR` emelés törhet. Az első
  éles kliens-integrációval jön az `1.0.0`, onnantól csak a major emelés törhet. A törő változások
  teendői az [UPGRADE.md](UPGRADE.md)-ben, a változások a [CHANGELOG.md](CHANGELOG.md)-ben.
- **Kiadás:**
  1. `composer quality` zöld, a CI (a teljesítményjob is) zöld;
  2. a `CHANGELOG.md` `[Unreleased]` szakasza verziószámot és dátumot kap;
  3. git tag `vX.Y.Z` a `main`-en, és egy GitHub release a CHANGELOG szakaszával;
  4. ellenőrzés egy friss Laravel 13 appban:
     `docker compose run --rm php sh bin/release-smoke.sh '^X.Y'`. A szkript a README telepítési
     lépéseit játssza végig (a két VCS bejegyzés, `composer require`, User modell, config, Sanctum,
     `migrate`), majd egy regisztrációt, egy pusht és egy pullt futtat. Argumentum nélkül a
     munkapéldányt telepíti, így a tag előtt is lefuttatható.

## Licenc

MIT
