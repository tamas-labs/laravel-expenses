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
