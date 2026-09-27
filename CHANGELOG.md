# Changelog

A formátum a [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) ajánlását követi, a verziózás
a [Semantic Versioning](https://semver.org/)-et.

## [Unreleased]

### Added

- Csomagváz: `ExpensesServiceProvider` auto-discoveryvel és publikálható `expenses` configgal, a
  `tamas-labs/expenses-schema` (`^1.0.2`, szerződés 1.2) függőség VCS repositoryból, Pest és
  Testbench tesztek MySQL 8.4-en, Larastan (`level: max`), Pint, Docker Compose fejlesztői környezet és
  GitHub Actions CI (PHP 8.3 `prefer-lowest`, 8.4, 8.5).
- Szerződés-integráció:
  - `ContractValidator` (opis `CompliantValidator`, a sémák a csomagból oldódnak fel, hálózat nélkül):
    bejövő adat, nyers JSON és kimenő payload validálása erőforrásnév vagy séma-`$id` szerint;
  - a hibák a TS oldal `issues` alakjában jönnek (`path`, `keyword`, `message`);
  - `ContractViolation` 422-es `protocol/error` borítékkal;
  - `expenses.contract` middleware az `X-Expenses-Contract` fejléc egyeztetésére;
  - paritás-tesztek a schema repó közös fixture-jeivel.
- Adatmodell:
  - migrációk minden erőforráshoz, `(user_id, id)` kompozit kulccsal (a kliens seed UUID-jai minden
    felhasználónál azonosak), felhasználón belüli összetett FK-kkal (`RESTRICT`), és generált
    oszlopos, byte-pontos egyedi kulcsokkal, amelyek csak az élő rekordokra vonatkoznak;
  - `datetime(3)` UTC időbélyegek, `double` mennyiségek bitpontos tárolással, JSON szövegoszlopok
    kulcssorrend-megőrzéssel;
  - a `currencies` tábla a HUF, EUR és USD sorral, a `sync_sequence` sorszámláló;
  - a host `users` tábla bővítése (`uuid`, profilmezők), és a `HasExpensesProfile` trait a host User
    modelljére;
  - Eloquent modellek (`ownedBy()`, `alive()`, `changedSince()` scope-ok), factory-k;
  - új config kulcsok: `expenses.user_model`, `expenses.database.table_prefix`;
  - a migrációk publikálhatók (`expenses-migrations` tag).
- Mapping réteg:
  - `ResourceRegistry` (singleton): az összes erőforrás egy helyen, scope-pal, push-sorrenddel és a
    szerződés-minorral, amelyben megjelent;
  - erőforrásonként egy deklaratív mapper (`RecordMapper` + `Field`-lista): validált rekord →
    modellattribútumok, modell → szerződés szerinti payload a séma kulcssorrendjében;
  - az időbélyegek kifelé mindig `…T08:30:00.123Z` alakúak (UTC, az app időzónájától függetlenül),
    befelé UTC-re váltva és ezredmásodpercre vágva; a szökőmásodperc rekordszintű elutasítás
    (`MappingRejection`);
  - `JsonText` cast a JSON szövegoszlopokra: a `{}` és a `[]` megkülönböztetve, a kulcssorrend
    megőrizve;
  - a zseb `categoryIds`-e a pivotból, UUID szerint rendezve megy ki.
- Domain szabályok:
  - `DomainValidator` (singleton): minden pusholható erőforrás szabályai egy helyen; előbb a
    rekordszintű (`RecordRule`), majd a tömeges, adatbázist olvasó (`BatchRule`) szabályok, egy rekord
    minden hibája visszajön;
  - `RuleContext`: a push eddig elfogadott és elutasított rekordjai, a pusholt rekordok szerverállapota
    és a pénznemlista, amelyet a szinkron (06) tölt;
  - `reference`: a hivatkozott rekord a felhasználónál létezik (a tombstone is, a push korábban
    elfogadott rekordja is, az elutasított nem); más felhasználó rekordjára ugyanaz a hiba jön, mint
    egy nem létezőre;
  - `unique`: egyediség az élő rekordok között, byte-pontosan, a push végállapota szerint, a
    `conflictingId`-vel;
  - `retired`: kivezetett pénznemet csak az a rekord tarthat meg, amelynek már az volt;
  - `amountSum`, `sourceExpense`, `archivedAt`: a kiadás összege, az ár forrása és a lista
    archiválása;
  - a tömeges szabályok erőforrásonként és táblánként legfeljebb egy lekérdezést futtatnak, a
    rekordok számától függetlenül.
- Szinkron motor és API:
  - `POST {prefix}/sync/push`: rekordonkénti eredmény (`accepted`, `stale` a szerver változatával,
    `rejected` a hibákkal), a kérés csoportosításában és sorrendjében. Egy tranzakcióban,
    függőségi sorrendben alkalmazva: egy szülő és a gyereke egy push-ban is mehet;
  - LWW: a későbbi `updatedAt` nyer (ezredmásodpercre, időpontként), döntetlennél a szerver; a
    törlés is módosítás, egy újabb élő változat feltámasztja a rekordot;
  - a push idempotens: a változatlan rekord nem íródik, és nem kap új sorszámot;
  - két rekord egy push-ban nevet cserélhet;
  - boríték-szintű hibák a `protocol/error` borítékkal: `contract_violation`,
    `resource_not_writable`, `duplicate_record`, `too_many_records`;
  - `GET {prefix}/sync/pull?cursor=…&limit=…`: minden változás egy globális, átlátszatlan cursor
    óta, tombstone-okkal, lapozva. A `user` csoport a saját fiók rekordját, a `currency` a
    pénznemeket hozza. `cursor_malformed` (422), `cursor_expired` (410);
  - a régebbi minor verziójú kliens nem kapja meg és nem pusholhatja az újabb erőforrásokat;
  - a sorszámot foglaló tranzakciók a sorszámok sorrendjében commitolnak, és a pull a kezdetekor
    commitolt sorszámig olvas, így párhuzamos push-ok mellett sem hagy ki változást;
  - `SyncStore`: a szinkronizált táblák minden lekérdezése egy helyen, tulajdonosra szűrve (a domain
    szabályoké is), teszt tiltja a megkerülését;
  - `RecordsPushed` esemény a commit után, ha a push írt;
  - új config kulcsok: `expenses.routes.prefix`, `expenses.routes.middleware`,
    `expenses.sync.push_max_records`, `expenses.sync.pull_default_limit`,
    `expenses.sync.pull_max_limit`.
- Felhasználó és hitelesítés:
  - `POST {prefix}/auth/register` és `…/auth/login`: e-mail (trimmelve, kisbetűsítve), jelszó és
    `deviceId` → a `user` rekord, az eszköz tokenpárja és az `account` állapot (`emailVerified`).
    A kérések a szerződés `protocol/` dokumentumai szerint validálódnak (`validation_failed`), a
    foglalt e-mail `email_taken`;
  - access token: Sanctum personal access token eszközönként, `expenses:access` képességgel, 60 perc;
    refresh token: saját `refresh_tokens` tábla (csak a SHA-256 hash-e), 90 napos csúszó lejárattal,
    rotációval és újrafelhasználás-észleléssel (`refresh_token_reused`);
  - `devices` tábla: egy eszköz egyszerre egy fiókhoz tartozik, egy másik fiókba belépve átkerül, és a
    régi fiók tokenjei rajta visszavonódnak; `POST …/auth/logout` leválasztja;
  - a bejelentkezés egyetlen általános hibája (`invalid_credentials`), ál-hash ellenőrzés nem létező
    fióknál, fékezés (`too_many_attempts`, `Retry-After`);
  - `GET`, `PATCH` (LWW, csak a `displayName`, a `defaultCurrencyId` és az `updatedAt` írható,
    `field_not_writable`) és `DELETE {prefix}/me` (jelszóval, a fiók minden adatával együtt;
    `DeleteAccount` szolgáltatás a host webes törlőoldalához, `AccountDeleted` esemény);
  - jelszó-visszaállítás a Laravel password brokerével (mindig 202, siker után minden token és eszköz
    visszavonva) és e-mail-megerősítés `MustVerifyEmail`-lel (aláírt link a szerződésbeli `uuid`-dal,
    átirányítás a beállított oldalra); a megerősítés configgal a szinkron feltétele lehet;
  - a levelek a kérés nyelvén (`hu`, `en`), magyar fordítással;
  - a szinkron route-ok `auth:sanctum` és `expenses:access` képesség mögött; a 401 és a 403 a
    szerződés hibaborítékában (`unauthenticated`, `forbidden`);
  - a jelszó és a tokenek nem kerülnek naplóba (`dontFlash`, `#[SensitiveParameter]`);
  - új függőség: `laravel/sanctum`; új config kulcsok: `expenses.auth.access_ttl`,
    `expenses.auth.refresh_ttl`, `expenses.auth.password_reset_url`,
    `expenses.auth.email_verified_url`, `expenses.auth.require_verified_email`.
