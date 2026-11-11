<?php

namespace Php7Compat\Tests\Mysql;

/**
 * Deprecation notices raised by the mysql_* functions, matching PHP 5.6.
 */
class DeprecationTest extends MysqlTestCase
{
    private const EXTENSION_NOTICE = '(): The mysql extension is deprecated and will be removed in the future: use mysqli or PDO instead';

    protected function setUp(): void
    {
        parent::setUp();
        \Php7Compat::$deprecations = true;
    }

    protected function tearDown(): void
    {
        \Php7Compat::$deprecations = false;
        parent::tearDown();
    }

    /**
     * @return list<array{0: int, 1: string}>
     */
    private function errorsFrom(callable $callback): array
    {
        return $this->captureErrors($callback)[1];
    }

    public function testConnectRaisesExtensionNotice(): void
    {
        $this->assertSame([[E_USER_DEPRECATED, 'mysql_connect' . self::EXTENSION_NOTICE]], $this->errorsFrom(function () {
            return mysql_connect(self::server(), self::user(), self::pass());
        }));
    }

    public function testPconnectRaisesExtensionNotice(): void
    {
        $this->assertSame([[E_USER_DEPRECATED, 'mysql_pconnect' . self::EXTENSION_NOTICE]], $this->errorsFrom(function () {
            return mysql_close(mysql_pconnect(self::server(), self::user(), self::pass()));
        }));
    }

    public function testAutoConnectRaisesExtensionNoticeNamingTheCaller(): void
    {
        $this->closeAllLinks();
        $this->useTestServerAsDefault();
        $this->assertSame([[E_USER_DEPRECATED, 'mysql_query' . self::EXTENSION_NOTICE]], $this->errorsFrom(function () {
            return mysql_query('SELECT 1');
        }));
    }

    public function testOrdinaryFunctionsRaiseNoNotices(): void
    {
        $this->assertNoErrors($this->errorsFrom(function () {
            $result = mysql_query('SELECT id, name FROM compat_people');
            mysql_fetch_assoc($result);
            mysql_field_name($result, 0);
            mysql_real_escape_string("'");
            mysql_db_name($result, 0);
            mysql_tablename($result, 0);
            mysql_table_name($result, 0);
            return mysql_free_result($result);
        }));
    }

    public function testDbQuery(): void
    {
        $this->assertSame(
            [
                [E_USER_DEPRECATED, 'Function mysql_db_query() is deprecated'],
                [E_USER_DEPRECATED, 'mysql_db_query(): This function is deprecated; use mysql_query() instead'],
            ],
            $this->errorsFrom(function () {
                return mysql_db_query(self::dbName(), 'SELECT 1');
            })
        );
    }

    public function testDbQueryWithBadLinkRaisesOnlyTheFunctionNotice(): void
    {
        $errors = $this->errorsFrom(function () {
            return mysql_db_query(self::dbName(), 'SELECT 1', false);
        });
        $this->assertCount(2, $errors);
        $this->assertSame([E_USER_DEPRECATED, 'Function mysql_db_query() is deprecated'], $errors[0]);
        $this->assertSame(E_USER_WARNING, $errors[1][0]);
    }

    public function testListDbs(): void
    {
        $this->assertSame(
            [
                [E_USER_DEPRECATED, 'Function mysql_list_dbs() is deprecated'],
                [E_USER_DEPRECATED, 'mysql_list_dbs(): This function is deprecated; use mysql_query() with SHOW DATABASES instead'],
            ],
            $this->errorsFrom(function () {
                return mysql_list_dbs($this->link);
            })
        );
    }

    public function testListTables(): void
    {
        $this->assertSame([[E_USER_DEPRECATED, 'Function mysql_list_tables() is deprecated']], $this->errorsFrom(function () {
            return mysql_list_tables(self::dbName());
        }));
    }

    public function testCreateAndDropDbAreDeprecated(): void
    {
        // The database already exists, so these fail, but the notices come first.
        $errors = $this->errorsFrom(function () {
            return mysql_create_db(self::dbName());
        });
        $this->assertSame([E_USER_DEPRECATED, 'Function mysql_create_db() is deprecated'], $errors[0]);

        $errors = $this->errorsFrom(function () {
            return mysql_drop_db('php7compat_no_such_db');
        });
        $this->assertSame([E_USER_DEPRECATED, 'Function mysql_drop_db() is deprecated'], $errors[0]);
    }

    public function testDeprecatedAliasRaisesItsOwnNoticeOnly(): void
    {
        $this->assertSame([[E_USER_DEPRECATED, 'Function mysql_fieldname() is deprecated']], $this->errorsFrom(function () {
            return mysql_fieldname(mysql_query('SELECT 1'), 0);
        }));
    }

    /** The alias replaces the target's "Function ...() is deprecated" notice, and its name is used in other notices. */
    public function testAliasOfDeprecatedFunction(): void
    {
        $this->assertSame(
            [
                [E_USER_DEPRECATED, 'Function mysql() is deprecated'],
                [E_USER_DEPRECATED, 'mysql(): This function is deprecated; use mysql_query() instead'],
            ],
            $this->errorsFrom(function () {
                return mysql(self::dbName(), 'SELECT 1');
            })
        );
        $this->assertSame([[E_USER_DEPRECATED, 'Function mysql_listtables() is deprecated']], $this->errorsFrom(function () {
            return mysql_listtables(self::dbName());
        }));
    }

    /**
     * @dataProvider deprecatedAliases
     */
    public function testDeprecatedAliases(string $alias, array $args): void
    {
        $errors = $this->errorsFrom(function () use ($alias, $args) {
            $args = array_map(function ($arg) {
                return $arg === 'RESULT' ? mysql_query('SELECT id, name FROM compat_people') : $arg;
            }, $args);
            return $alias(...$args);
        });
        $this->assertSame([E_USER_DEPRECATED, "Function $alias() is deprecated"], $errors[0]);
    }

    public function deprecatedAliases(): array
    {
        return [
            ['mysql_fieldtable', ['RESULT', 0]],
            ['mysql_fieldlen', ['RESULT', 0]],
            ['mysql_fieldtype', ['RESULT', 0]],
            ['mysql_fieldflags', ['RESULT', 0]],
            ['mysql_selectdb', ['php7compat_test']],
            ['mysql_createdb', ['php7compat_test']],
            ['mysql_dropdb', ['php7compat_no_such_db']],
            ['mysql_freeresult', ['RESULT']],
            ['mysql_numfields', ['RESULT']],
            ['mysql_numrows', ['RESULT']],
            ['mysql_listdbs', []],
            ['mysql_listfields', ['php7compat_test', 'compat_people']],
            ['mysql_dbname', ['RESULT', 0]],
        ];
    }
}
