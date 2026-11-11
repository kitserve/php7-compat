# Testing

The test suite uses [PHPUnit](https://phpunit.de/) 9.6, which supports PHP 7.3 and later. The library itself supports PHP 7.0+, but versions before 7.4 aren't tested.

## Quick start

```sh
composer install
vendor/bin/phpunit
```

Without a database configured, the `mysql_*` tests are skipped and only the `ereg` and miscellaneous tests run. They need nothing beyond PHP.

## Running the `mysql_*` tests

The `mysql_*` tests need a real MySQL or MariaDB server. Point them at it with environment variables:

| Variable              | Meaning                                  | Default           |
|-----------------------|------------------------------------------|-------------------|
| `PHP7COMPAT_DB_HOST`  | Server host. Leave empty to skip the tests. | (empty)        |
| `PHP7COMPAT_DB_PORT`  | Server port                              | `3306`            |
| `PHP7COMPAT_DB_USER`  | User name                                | (empty)           |
| `PHP7COMPAT_DB_PASS`  | Password                                 | (empty)           |
| `PHP7COMPAT_DB_NAME`  | Database to use                          | `php7compat_test` |

The tests create and drop the tables `compat_people` and `compat_types`, plus one oddly named table, in that database, so **don't point them at a database you care about**. The user needs full privileges on the test database. The `mysql_create_db()` / `mysql_drop_db()` test also needs permission to create the `php7compat_test_tmp` database. Without it, that one test is skipped.

### Using a throwaway Docker database

The easiest option is a disposable container:

```sh
docker run -d --rm --name php7compat-test-db \
    -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=php7compat_test \
    -p 127.0.0.1:33306:3306 mariadb:10.11

PHP7COMPAT_DB_HOST=127.0.0.1 PHP7COMPAT_DB_PORT=33306 \
PHP7COMPAT_DB_USER=root PHP7COMPAT_DB_PASS=root \
    vendor/bin/phpunit

docker stop php7compat-test-db
```

For MySQL, use `mysql:8.0` with `MYSQL_ROOT_PASSWORD` / `MYSQL_DATABASE` instead. Use `127.0.0.1` rather than `localhost`, otherwise the client tries a Unix socket instead of the mapped port.

Don't run two test runs against the same database at the same time. They share the fixture tables.

### Running part of the suite

```sh
vendor/bin/phpunit --testsuite ereg      # ereg/split/sql_regcase only
vendor/bin/phpunit --testsuite mysql     # mysql_* only
vendor/bin/phpunit --filter testDataSeek # a single test
```

## Testing other PHP versions

Continuous integration (below) covers every supported PHP version. To try a specific version locally, the official PHP Docker images work. Composer's `platform` setting lets you install dependencies for the target version on the host, which avoids needing `unzip` and other tools inside the container:

```sh
cp -r . /tmp/php7compat-74 && cd /tmp/php7compat-74
rm -rf vendor composer.lock
composer config platform.php 7.4.33 && composer update

docker run --rm --network host -v "$PWD":/app -w /app php:7.4-cli sh -c '
    docker-php-ext-install mysqli >/dev/null &&
    PHP7COMPAT_DB_HOST=127.0.0.1 PHP7COMPAT_DB_PORT=33306 \
    PHP7COMPAT_DB_USER=root PHP7COMPAT_DB_PASS=root vendor/bin/phpunit'
```

Some code paths depend on the PHP version, so it's worth testing at least one version from each group:
* 7.x: no `WeakMap`.
* 8.0: mysqli still reports `max_length`.
* 8.1+: `max_length` is emulated, and mysqli throws exceptions by default.
* 8.4+: `mysqli_ping()` is deprecated.

## Continuous integration

`.github/workflows/tests.yml` runs the whole suite on GitHub Actions for PHP 7.4 and 8.0 to 8.5, against both MySQL 8.0 and MariaDB 10.11, on every push and pull request. GitHub Actions is free for public repositories.

## Writing tests

* Tests needing a database extend `Php7Compat\Tests\Mysql\MysqlTestCase`. It gives each test a fresh default link (`$this->link`), fresh fixture tables, and helpers for ini settings (`setIni()`, `useTestServerAsDefault()`, `useUnreachableServerAsDefault()`, `closeAllLinks()`). Other tests extend `Php7Compat\Tests\TestCase`.
* Many original functions warned *and* returned a value. Use `captureErrors()` to run a call and collect its warnings without PHPUnit turning them into exceptions. Then check them with `assertWarning()`, `assertSingleError()` or `assertNoErrors()`. Errors suppressed with `@` are not captured, just as PHP would not report them.
* Deprecation notices are switched off for the whole suite in `tests/bootstrap.php`, so they don't interfere with tests that check for exact warnings. Tests that check the notices themselves set `\Php7Compat::$deprecations = true` (see the two `DeprecationTest` classes).
* **Expected values must come from the behaviour of the original PHP 5 functions, not from what the wrapper currently does.** The PHP manual and the PHP 5.6 source (`ext/mysql/php_mysql.c`, `ext/ereg/ereg.c` and the bundled `regex/` library) are the references. Where the original behaviour can't be reproduced, document the difference in the README's "Known differences" section, and add a test that pins down what the wrapper does instead.
