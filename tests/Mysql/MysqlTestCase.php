<?php

namespace Php7Compat\Tests\Mysql;

use Php7Compat\Tests\TestCase;

/**
 * Base class for tests that need a real MySQL/MariaDB server.
 *
 * Connection details come from the PHP7COMPAT_DB_* environment variables
 * (see TESTING.md). If PHP7COMPAT_DB_HOST is empty the tests are skipped.
 *
 * Each test starts with a fresh default link ($this->link) on the test
 * database, containing the compat_people and compat_types fixture tables.
 */
abstract class MysqlTestCase extends TestCase
{
    /** @var \mysqli */
    protected $link;

    /** @var array<string, string|false> ini settings to restore after the test */
    private $savedIni = [];

    protected function setUp(): void
    {
        if ((string) getenv('PHP7COMPAT_DB_HOST') === '') {
            $this->markTestSkipped('No test database configured; set PHP7COMPAT_DB_HOST etc. (see TESTING.md)');
        }

        \Php7Compat::reset();
        $this->link = mysql_connect(self::server(), self::user(), self::pass(), true);
        $this->assertInstanceOf(\mysqli::class, $this->link, 'Could not connect to test database: ' . mysql_error());
        $this->assertTrue(mysql_select_db(self::dbName(), $this->link));

        $this->createFixtures();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedIni as $name => $value) {
            ini_set($name, (string) $value);
        }
        $this->savedIni = [];

        if (isset($this->link)) {
            // Drop fixtures over a fresh connection in case the test closed $this->link.
            $cleanup = mysqli_connect(self::host(), self::user(), self::pass(), self::dbName(), self::port());
            mysqli_query($cleanup, 'DROP TABLE IF EXISTS compat_people, compat_types');
            mysqli_close($cleanup);
        }

        foreach (\Php7Compat::$links as $link) {
            if (\Php7Compat::link_is_open($link)) {
                mysqli_close($link);
            }
        }
        \Php7Compat::reset();
    }

    public static function host(): string
    {
        return (string) getenv('PHP7COMPAT_DB_HOST');
    }

    public static function port(): int
    {
        return (int) (getenv('PHP7COMPAT_DB_PORT') ?: 3306);
    }

    public static function user(): string
    {
        return (string) getenv('PHP7COMPAT_DB_USER');
    }

    public static function pass(): string
    {
        return (string) getenv('PHP7COMPAT_DB_PASS');
    }

    public static function dbName(): string
    {
        return (string) (getenv('PHP7COMPAT_DB_NAME') ?: 'php7compat_test');
    }

    /** Server string in the "host:port" form accepted by mysql_connect(). */
    public static function server(): string
    {
        return self::host() . ':' . self::port();
    }

    /** Set an ini value for the duration of this test only. */
    protected function setIni(string $name, string $value): void
    {
        if (!array_key_exists($name, $this->savedIni)) {
            $this->savedIni[$name] = ini_get($name);
        }
        ini_set($name, $value);
    }

    /** Point the mysqli.default_* settings at the test server so auto-connect works. */
    protected function useTestServerAsDefault(): void
    {
        $this->setIni('mysqli.default_host', self::host());
        $this->setIni('mysqli.default_port', (string) self::port());
        $this->setIni('mysqli.default_user', self::user());
        $this->setIni('mysqli.default_pw', self::pass());
    }

    /** Point the mysqli.default_* settings at a port nothing listens on. */
    protected function useUnreachableServerAsDefault(): void
    {
        $this->setIni('mysqli.default_host', '127.0.0.1');
        $this->setIni('mysqli.default_port', '1');
        $this->setIni('mysqli.default_user', 'nobody');
        $this->setIni('mysqli.default_pw', '');
    }

    /** Close every link and forget the default, leaving no connection at all. */
    protected function closeAllLinks(): void
    {
        foreach (\Php7Compat::$links as $link) {
            if (\Php7Compat::link_is_open($link)) {
                mysqli_close($link);
            }
        }
        \Php7Compat::reset();
    }

    private function createFixtures(): void
    {
        $queries = [
            'DROP TABLE IF EXISTS compat_people, compat_types',
            'CREATE TABLE compat_people (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(20) NOT NULL,
                email VARCHAR(50) NULL,
                score DECIMAL(5,2) NULL,
                created DATETIME NULL,
                bio TEXT NULL,
                UNIQUE KEY (email),
                KEY (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            "INSERT INTO compat_people (name, email, score, created, bio) VALUES
                ('Alice', 'alice@example.com', 9.5, '2020-01-01 00:00:00', 'Likes cats'),
                ('Bob', NULL, 7.25, NULL, NULL),
                ('Carol', 'carol@example.com', 8, '2021-06-15 12:30:00', 'A much longer biography text')",
            "CREATE TABLE compat_types (
                d DATE NULL,
                t TIME NULL,
                ts TIMESTAMP NULL,
                y YEAR NULL,
                e ENUM('a','b') NULL,
                s SET('x','y') NULL,
                c CHAR(3) NULL,
                f FLOAT NULL,
                dbl DOUBLE NULL,
                bi BIGINT NULL,
                ti TINYINT NULL,
                si SMALLINT NULL,
                mi MEDIUMINT NULL,
                bl BLOB NULL
            ) ENGINE=InnoDB",
        ];
        foreach ($queries as $sql) {
            $this->assertTrue(mysql_query($sql, $this->link), "Fixture query failed: " . mysql_error($this->link));
        }
    }
}
