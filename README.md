# tamas-labs/laravel-expenses

**Teljes dokumentáció:** [Magyar](README.hu.md) · [English](README.en.md)

Laravel 13 csomag: az Expenses mobilalkalmazás szinkron-backendje. Regisztráció, bejelentkezés,
tokenes hitelesítés, a felhasználónkénti rekordok tárolása, push és pull. A rekordok és a
szinkronprotokoll alakját a [`tamas-labs/expenses-schema`](https://github.com/tamas-labs/expenses-schema)
szerződés határozza meg, a csomag ezt valósítja meg a szerveren.

Szerződés: **1.2** · PHP 8.3–8.5 · Laravel 13 · MySQL 8.4+ · Licenc: MIT

> **Állapot:** `0.x`, a kliens szinkronja még nem éles: egy minor emelés is törhet
> ([UPGRADE.md](UPGRADE.md)).

## Telepítés

Egyik csomag sincs a Packagist-on: a host app `composer.json`-jába **mindkét** repó kell.

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/tamas-labs/laravel-expenses" },
    { "type": "vcs", "url": "https://github.com/tamas-labs/expenses-schema" }
]
```

```bash
composer require tamas-labs/laravel-expenses:^0.1
php artisan install:api   # a Sanctum tokentáblája
php artisan migrate
```

Utána kell még: a User modell traitjei, a két kötelező URL (`EXPENSES_PASSWORD_RESET_URL`,
`EXPENSES_EMAIL_VERIFIED_URL`), a levélküldés és az ütemezett karbantartás. A lépések a
[teljes dokumentációban](README.hu.md#telepítés).

## Fejlesztői szerver a klienshez

```bash
docker compose up app   # http://localhost:8080/api/expenses, emulátorból http://10.0.2.2:8080
```

Tesztfelhasználó: `dev@example.com` / `password`, a kliens seed adataival.
[Részletek](README.hu.md#fejlesztői-szerver).

## Fejlesztés

```bash
docker compose run --rm php composer install
docker compose run --rm php composer quality   # Pint, PHPStan (level max), Pest
```

Változások: [CHANGELOG.md](CHANGELOG.md).
