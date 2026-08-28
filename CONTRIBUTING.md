# Contributing

## Running the tests

```bash
composer install
php artisan test
```

The suite runs on SQLite by default and needs no setup — Passport signing keys
are generated automatically on first run.

### Running against PostgreSQL

Production runs PostgreSQL, and some defects are invisible on SQLite: it treats
an unresolvable double-quoted identifier as a string literal rather than
erroring, which has hidden real bugs. CI runs both. To run PostgreSQL locally:

```bash
docker run -d --name mnemon-pg -e POSTGRES_USER=mnemon -e POSTGRES_PASSWORD=mnemon \
  -e POSTGRES_DB=mnemon_test -p 55432:5432 pgvector/pgvector:pg17

DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 DB_DATABASE=mnemon_test \
DB_USERNAME=mnemon DB_PASSWORD=mnemon MNEMON_EMBEDDING_DRIVER=none \
php artisan test
```

You will need the `pdo_pgsql` PHP extension.

## Formatting

```bash
./vendor/bin/pint
```

## The Docker stack

`./docker/smoke.sh` boots the full stack, asserts it serves, restarts it, and
checks the bootstrap did not rotate credentials. CI runs it on every PR.
