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

## Fejlesztés

Minden parancs Dockerben fut (PHP 8.4 és MySQL 8.4):

```bash
docker compose run --rm php composer install
docker compose run --rm php composer quality   # Pint, PHPStan (level max), Pest
```

Külön is futtathatók: `composer test`, `composer analyse`, `composer format`, `composer format:check`.

## Licenc

MIT
