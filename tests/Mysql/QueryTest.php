<?php

namespace Php7Compat\Tests\Mysql;

/**
 * mysql_query(), mysql_db_query(), mysql_unbuffered_query(), mysql_select_db(),
 * error reporting, affected rows / insert id, and escaping.
 */
class QueryTest extends MysqlTestCase
{
    public function testSelectReturnsResult(): void
    {
        $result = mysql_query('SELECT * FROM compat_people');
        $this->assertInstanceOf(\mysqli_result::class, $result);
        $this->assertTrue(is_mysql_resource($result));
        $this->assertSame(3, mysql_num_rows($result));
    }

    public function testWriteQueryReturnsTrue(): void
    {
        $this->assertTrue(mysql_query("UPDATE compat_people SET score = 1 WHERE name = 'Bob'"));
        $this->assertSame(1, mysql_affected_rows());
    }

    public function testInsertId(): void
    {
        $this->assertTrue(mysql_query("INSERT INTO compat_people (name) VALUES ('Dave')"));
        $this->assertSame(4, mysql_insert_id());
        $this->assertSame(4, mysql_insert_id($this->link));
    }

    public function testFailedQueryReturnsFalseWithoutWarning(): void
    {
        [$result, $errors] = $this->captureErrors(function () {
            return mysql_query('SELEC nonsense');
        });
        $this->assertFalse($result);
        $this->assertNoErrors($errors);
        $this->assertSame(1064, mysql_errno());
        $this->assertStringContainsString('SQL syntax', mysql_error());
        $this->assertSame(1064, mysql_errno($this->link));
    }

    public function testFailedQueryDoesNotThrowWhenMysqliExceptionsAreEnabled(): void
    {
        $previous = (new \mysqli_driver())->report_mode;
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $this->assertFalse(mysql_query('SELEC nonsense'));
            $this->assertSame(1064, mysql_errno());
            $this->assertSame(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT, (new \mysqli_driver())->report_mode);
        } finally {
            mysqli_report($previous);
        }
    }

    public function testQueryWithoutIndexDoesNotThrowWhenIndexReportingIsEnabled(): void
    {
        $previous = (new \mysqli_driver())->report_mode;
        mysqli_report(MYSQLI_REPORT_ALL);
        try {
            $result = mysql_query('SELECT * FROM compat_people WHERE bio IS NULL');
            $this->assertInstanceOf(\mysqli_result::class, $result);
        } finally {
            mysqli_report($previous);
        }
    }

    public function testDieOnErrorIdiom(): void
    {
        $died = false;
        $result = mysql_query('SELEC nonsense') or $died = true;
        $this->assertTrue($died);
        $this->assertFalse($result);
    }

    public function testEmptyQueryFailsLikeTheOriginal(): void
    {
        [$result, $errors] = $this->captureErrors(function () {
            return mysql_query('');
        });
        $this->assertFalse($result);
        $this->assertNoErrors($errors);
        $this->assertSame(1065, mysql_errno());
    }

    public function testSelectDb(): void
    {
        $this->assertTrue(mysql_select_db('information_schema'));
        $this->assertSame('information_schema', mysql_result(mysql_query('SELECT DATABASE()'), 0));
    }

    public function testSelectDbFailureReturnsFalseWithoutWarning(): void
    {
        [$result, $errors] = $this->captureErrors(function () {
            return mysql_select_db('php7compat_no_such_db');
        });
        $this->assertFalse($result);
        $this->assertNoErrors($errors);
        $this->assertContains(mysql_errno(), [1044, 1049]);
    }

    public function testDbQueryWithTwoArgumentsUsesDefaultLink(): void
    {
        mysql_select_db('information_schema');
        $result = mysql_db_query(self::dbName(), 'SELECT COUNT(*) FROM compat_people');
        $this->assertSame('3', mysql_result($result, 0));
        $this->assertSame(self::dbName(), mysql_result(mysql_query('SELECT DATABASE()'), 0));
    }

    public function testDbQueryWithExplicitLink(): void
    {
        $result = mysql_db_query(self::dbName(), 'SELECT COUNT(*) FROM compat_people', $this->link);
        $this->assertSame('3', mysql_result($result, 0));
    }

    public function testDbQueryFailsIfDatabaseCannotBeSelected(): void
    {
        $this->assertFalse(mysql_db_query('php7compat_no_such_db', 'SELECT 1'));
    }

    public function testUnbufferedQuery(): void
    {
        $result = mysql_unbuffered_query('SELECT name FROM compat_people ORDER BY id');
        $names = [];
        while (($row = mysql_fetch_assoc($result)) !== false) {
            $names[] = $row['name'];
        }
        $this->assertSame(['Alice', 'Bob', 'Carol'], $names);
        $this->assertSame(3, mysql_num_rows($result));
    }

    public function testUnbufferedNumRowsCountsRowsFetchedSoFar(): void
    {
        $result = mysql_unbuffered_query('SELECT name FROM compat_people ORDER BY id');
        $this->assertSame(0, mysql_num_rows($result));
        mysql_fetch_row($result);
        $this->assertSame(1, mysql_num_rows($result));
        mysql_free_result($result);
    }

    public function testUnbufferedResultCannotSeek(): void
    {
        $result = mysql_unbuffered_query('SELECT name FROM compat_people ORDER BY id');
        [$ok, $errors] = $this->captureErrors(function () use ($result) {
            return mysql_data_seek($result, 0);
        });
        $this->assertFalse($ok);
        $this->assertWarning($errors, 'is invalid for MySQL result index');
        mysql_free_result($result);
    }

    public function testQueryWhileUnbufferedRowsPendingDiscardsThemWithNotice(): void
    {
        $unbuffered = mysql_unbuffered_query('SELECT name FROM compat_people ORDER BY id');
        mysql_fetch_row($unbuffered);

        [$result, $errors] = $this->captureErrors(function () {
            return mysql_query('SELECT 7');
        });
        $this->assertSingleError($errors, E_USER_NOTICE, 'Function called without first fetching all rows from a previous unbuffered query');
        $this->assertSame('7', mysql_result($result, 0));
    }

    public function testQueryAfterUnbufferedResultFullyReadNeedsNoNotice(): void
    {
        $unbuffered = mysql_unbuffered_query('SELECT name FROM compat_people ORDER BY id');
        while (mysql_fetch_row($unbuffered) !== false) {
        }
        [$result, $errors] = $this->captureErrors(function () {
            return mysql_query('SELECT 7');
        });
        $this->assertNoErrors($errors);
        $this->assertSame('7', mysql_result($result, 0));
    }

    public function testQueryAfterUnbufferedResultFreedNeedsNoNotice(): void
    {
        $unbuffered = mysql_unbuffered_query('SELECT name FROM compat_people ORDER BY id');
        mysql_fetch_row($unbuffered);
        $this->assertTrue(mysql_free_result($unbuffered));
        [$result, $errors] = $this->captureErrors(function () {
            return mysql_query('SELECT 7');
        });
        $this->assertNoErrors($errors);
        $this->assertSame('7', mysql_result($result, 0));
    }

    public function testRealEscapeString(): void
    {
        $this->assertSame("O\\'Reilly \\\"quoted\\\"\\n", mysql_real_escape_string("O'Reilly \"quoted\"\n"));
        $this->assertSame("O\\'Reilly", mysql_real_escape_string("O'Reilly", $this->link));
    }

    public function testEscapeStringNeedsNoConnection(): void
    {
        $this->closeAllLinks();
        [$result, $errors] = $this->captureErrors(function () {
            return mysql_escape_string("\0\n\r\\'\"\x1a plain");
        });
        $this->assertNoErrors($errors);
        $this->assertSame('\\0\\n\\r\\\\\\\'\\"\\Z plain', $result);
    }

    public function testSetCharsetAndClientEncoding(): void
    {
        $this->assertTrue(mysql_set_charset('latin1'));
        $this->assertSame('latin1', mysql_client_encoding());
        $this->assertTrue(mysql_set_charset('utf8mb4', $this->link));
        $this->assertSame('utf8mb4', mysql_client_encoding($this->link));
    }

    public function testInfoFunctions(): void
    {
        $this->assertIsString(mysql_get_client_info());
        $this->assertIsString(mysql_get_host_info());
        $this->assertSame(10, mysql_get_proto_info());
        $this->assertIsString(mysql_get_server_info());
        $this->assertIsInt(mysql_thread_id());
        $this->assertStringContainsString('Uptime', mysql_stat());

        mysql_query('UPDATE compat_people SET score = score');
        $this->assertStringContainsString('Rows matched: 3', mysql_info());
        mysql_query('SELECT 1');
        $this->assertFalse(mysql_info());
    }

    public function testCreateAndDropDb(): void
    {
        $name = 'php7compat_test_tmp';
        if (!@mysql_create_db($name)) {
            if (in_array(mysql_errno(), [1044, 1045, 1142], true)) {
                $this->markTestSkipped('Test user may not create databases');
            }
            @mysql_drop_db($name);
            $this->assertTrue(mysql_create_db($name), mysql_error());
        }
        $this->assertTrue(mysql_select_db($name));
        $this->assertTrue(mysql_drop_db($name));
        $this->assertFalse(mysql_drop_db($name));
    }
}
