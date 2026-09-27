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
php artisan install:api   # a Sanctum personal_access_tokens táblája, ha még nincs
```

A service providert az auto-discovery tölti be. A hitelesítés a
[Laravel Sanctum](https://laravel.com/docs/sanctum) access tokenjeire épül, a Sanctum a csomag
függősége. A tokentábla migrációja a hosté: az `install:api` vagy a
`vendor:publish --tag=sanctum-migrations` hozza létre.

Két környezeti változó kötelező, nélkülük a csomag induláskor érthető hibával leáll:

```dotenv
# A jelszó-visszaállító levél linkje: app deep link vagy a host oldala, {token} és {email} helyőrzővel
EXPENSES_PASSWORD_RESET_URL="expenses://reset-password?token={token}&email={email}"
# Ide vezet a megerősítő link megnyitása; érvénytelen linknél ?status=invalid kerül a végére
EXPENSES_EMAIL_VERIFIED_URL="https://example.com/email-verified"
```

### Felhasználói modell

A csomag a host `users` táblájára épít, és a migrációja ehhez oszlopokat ad (`uuid`,
`default_currency_id`, `registered_at`, `profile_created_at`, `profile_updated_at`, `server_seq`).
Ha ezek közül valamelyik már létezik, a migráció érthető hibával leáll. A User modellnek két traitet
kell használnia és a `MustVerifyEmail` interfészt implementálnia. A csomag induláskor ellenőrzi, és
érthető hibát ad, ha valamelyik hiányzik:

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

A regisztráció a `name`, `email`, `password` és a profiloszlopok kitöltésével hoz létre felhasználót
(`forceFill`, a `$fillable` nem számít). **Ha a host `users` táblájának más kötelező oszlopa is van,
annak alapértéket kell adni**, különben a regisztráció adatbázishibával elbukik.

### Migrációk

```bash
php artisan migrate
```

A csomag migrációi publikálás nélkül is lefutnak. Ha a host táblanevei ütköznének a csomagéival
(`categories`, `expenses`, …), az `EXPENSES_TABLE_PREFIX` (legfeljebb 7 karakter, pl. `exp_`) minden
csomagtábla elé prefixet tesz. **Az első migrálás előtt kell beállítani**, utólag nem módosítható.

Testre szabáshoz a migrációk publikálhatók (`--tag=expenses-migrations`). A másolat az eredeti
fájlnevekkel kerül a `database/migrations` könyvtárba, és a csomagé helyett fut, nem mellette.

## Végpontok

A csomag route-jai az `expenses.routes.prefix` (alapértelmezés: `api/expenses`) alatt vannak:

| Végpont                                  | Név                             | Hitelesítés  | Törzs / válasz                                  |
| ---------------------------------------- | ------------------------------- | ------------ | ----------------------------------------------- |
| `POST …/auth/register`                   | `expenses.auth.register`        | —            | `register-request` → 201 `auth-response`        |
| `POST …/auth/login`                      | `expenses.auth.login`           | —            | `login-request` → `auth-response`               |
| `POST …/auth/refresh`                    | `expenses.auth.refresh`         | —            | `refresh-request` → `refresh-response`          |
| `POST …/auth/logout`                     | `expenses.auth.logout`          | access token | — → 204                                         |
| `POST …/auth/password/forgot`            | `expenses.auth.password.forgot` | —            | `password-forgot-request` → 202                 |
| `POST …/auth/password/reset`             | `expenses.auth.password.reset`  | —            | `password-reset-request` → 204                  |
| `GET …/auth/email/verify/{uuid}/{hash}`  | `expenses.auth.email.verify`    | aláírt link  | — → átirányítás                                 |
| `POST …/auth/email/resend`               | `expenses.auth.email.resend`    | access token | — → 202                                         |
| `GET …/me`                               | `expenses.me.show`              | access token | — → `me-response`                               |
| `PATCH …/me`                             | `expenses.me.update`            | access token | a teljes `user` rekord → `me-update-result`     |
| `DELETE …/me`                            | `expenses.me.destroy`           | access token | `account-delete-request` → 204                  |
| `POST …/sync/push`                       | `expenses.sync.push`            | access token | `push-request` → `push-response`                |
| `GET …/sync/pull?cursor=<c>&limit=<n>`   | `expenses.sync.pull`            | access token | — → `pull-response`                             |

Az alakokat és a protokoll szabályait (LWW, cursor, hibakódok) a
[`tamas-labs/expenses-schema`](https://github.com/tamas-labs/expenses-schema) README-je írja le.
Minden route megkapja az `expenses.routes.middleware` middleware-eit (alapértelmezés: `api`,
`expenses.contract`), kivéve a megerősítő linket, amelyet a felhasználó böngészőben nyit meg. Az
`expenses.contract` maradjon a listában: ez egyezteti a kliens szerződésverzióját. A szinkron
korlátai az `expenses.sync` alatt állíthatók: legfeljebb 500 rekord egy push-ban, a pull lapmérete
alapértelmezésben 500, legfeljebb 1000.

### Hitelesítés

- **Access token:** Sanctum personal access token `expenses:access` képességgel, eszközönként
  (`device:<deviceId>` néven), 60 percig érvényes (`expenses.auth.access_ttl`). Az
  `Authorization: Bearer` fejlécben utazik. A védett route-ok az `auth:sanctum` és a Sanctum
  `CheckAbilities` middleware-ét kapják: token nélkül 401 `unauthenticated`, a képesség nélküli
  tokenre 403 `forbidden` jön, mindkettő a szerződés hibaborítékában.
- **Refresh token:** 90 napig érvényes (`expenses.auth.refresh_ttl`), csak a `/auth/refresh`
  törzsében utazik. Minden frissítés elhasználja, és új párt ad, így a lejárat csúszó. Egy elhasznált
  refresh token ismételt bemutatása lopásra utal: a tokenlánca és az eszköz access tokenjei
  visszavonódnak (401 `refresh_token_reused`). A szerver csak a SHA-256 hash-ét tárolja.
- **Eszközök:** egy eszköz (`deviceId`) egyszerre egy fiókhoz tartozik. Egy másik fiókba belépve az
  eszköz átkerül, és a régi fiók tokenjei ezen az eszközön visszavonódnak. A kijelentkezés az eszköz
  tokenjeit visszavonja, és leválasztja az eszközt.
- **Bejelentkezés:** minden hiba ugyanaz a 401 `invalid_credentials`. Nem létező fióknál is lefut
  egy hash-ellenőrzés. Percenként 5 sikertelen próbálkozás engedett `(e-mail, IP)` páronként, és 20
  IP-nként, utána 429 `too_many_attempts` jön `Retry-After` fejléccel. A regisztráció IP-nként óránként
  10, a visszaállító levél kérése IP-nként óránként 5, a megerősítő levél újraküldése
  felhasználónként óránként 3 alkalommal kérhető.
- **Jelszó:** legalább 8 karakter, bcrypt hash-elő mellett legfeljebb 72 bájt (a bcrypt a
  hosszabbat csendben levágná), más hash-elővel legfeljebb 256 karakter.

### E-mail-megerősítés és jelszó-visszaállítás

A Laravel beépített eszközei mennek (`MustVerifyEmail`, password broker, `VerifyEmail` és
`ResetPassword` értesítés), a levelek a host `mail` beállításával. Regisztráció után a csomag elküldi
a megerősítő levelet. A megerősítés alapból nem feltétele a szinkronnak. Az
`expenses.auth.require_verified_email` bekapcsolásával a szinkron és a profilmódosítás
403 `email_not_verified`-et ad megerősítés előtt. A jelszó-visszaállítás a fiók minden tokenjét és
eszközét visszavonja.

A levelek linkjét a csomag a `VerifyEmail::createUrlUsing()` és a `ResetPassword::createUrlUsing()`
hívással állítja be, **de csak akkor, ha a host nem tette meg** (egy service provider `boot()`-jában).
A levél nyelve a kérés `Accept-Language` fejlécéből jön (`hu` vagy `en`, egyébként az app
alapértelmezése). A csomag magyar fordítást szállít a Laravel alapértelmezett szövegeihez, a host
`lang/hu.json`-ja felülírhatja.

### Fióktörlés

A `DELETE …/me` a jelszó újbóli megadása után egy tranzakcióban törli a fiók minden adatát, eszközét
és tokenjét, végül a `users` sort. A Google Play a webes törlést is megköveteli: ez a host dolga, egy
egyszerű oldallal, amely a felhasználó azonosítása után ugyanezt a szolgáltatást hívja:

```php
app(\TamasLabs\LaravelExpenses\Auth\DeleteAccount::class)($user);
```

### Naplózás

A csomag a `password`, `token`, `accessToken` és `refreshToken` mezőt felveszi a kivételkezelő
`dontFlash` listájára, és a jelszót és a tokeneket kezelő paramétereket `#[SensitiveParameter]`-rel
jelöli, így azok a stack trace-ben sem látszanak. Sikertelen bejelentkezéskor csak az e-mail
SHA-256 hash-e és az IP kerül a naplóba.

## Események

A host app figyelhet rájuk, a csomag maga nem használja őket:

- `TamasLabs\LaravelExpenses\Sync\Events\RecordsPushed`: egy push az adatbázisba is írt (a commit
  után). Benne a felhasználó és az erőforrásonként írt rekordok száma.
- `TamasLabs\LaravelExpenses\Auth\Events\AccountDeleted`: egy fiók törlődött (a commit után). Benne
  a felhasználó szerződésbeli `uuid`-ja.
- A Laravel saját `Verified` és `PasswordReset` eseménye a megerősítéskor és a jelszó cseréjekor.

## Fejlesztés

Minden parancs Dockerben fut (PHP 8.4 és MySQL 8.4):

```bash
docker compose run --rm php composer install
docker compose run --rm php composer quality   # Pint, PHPStan (level max), Pest
```

Külön is futtathatók: `composer test`, `composer analyse`, `composer format`, `composer format:check`.

## Licenc

MIT
