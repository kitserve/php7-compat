<?php

namespace Php7Compat\Tests\Mysql;

/**
 * mysql_connect(), mysql_pconnect(), mysql_close(), default-link handling,
 * auto-connect, and connection error reporting.
 */
class ConnectionTest extends MysqlTestCase
{
    public function testConnectReturnsLinkAndMakesItTheDefault(): void
    {
        $this->assertTrue(is_mysql_resource($this->link));
        $this->assertSame(mysqli_thread_id($this->link), mysql_thread_id());
    }

    public function testDefaultLinkIsTheMostRecentlyOpened(): void
    {
        $second = mysql_connect(self::server(), self::user(), self::pass(), true);
        $this->assertNotSame($this->link, $second);
        $this->assertSame(mysqli_thread_id($second), mysql_thread_id());

        $third = mysql_connect(self::server(), self::user(), self::pass(), true);
        $this->assertSame(mysqli_thread_id($third), mysql_thread_id());
    }

    public function testConnectWithSameArgumentsReusesTheLink(): void
    {
        $a = mysql_connect(self::server(), self::user(), self::pass());
        $b = mysql_connect(self::server(), self::user(), self::pass());
        $this->assertSame($a, $b);
        $this->assertSame($this->link, $a, 'setUp opened a link with identical arguments');
    }

    public function testReusingAnOlderLinkMakesItTheDefaultAgain(): void
    {
        $other = mysql_connect(self::server(), self::user(), self::pass(), true);
        $this->assertSame(mysqli_thread_id($other), mysql_thread_id());

        // Same arguments as $other, so returns $other (the link registered most recently).
        $again = mysql_connect(self::server(), self::user(), self::pass());
        $this->assertSame($other, $again);
        $this->assertSame(mysqli_thread_id($other), mysql_thread_id());
    }

    public function testNewLinkForcesANewConnection(): void
    {
        $b = mysql_connect(self::server(), self::user(), self::pass(), true);
        $this->assertNotSame($this->link, $b);
        $this->assertNotSame(mysqli_thread_id($this->link), mysqli_thread_id($b));
    }

    public function testConnectAfterCloseOpensAFreshLink(): void
    {
        $this->assertTrue(mysql_close($this->link));
        $c = mysql_connect(self::server(), self::user(), self::pass());
        $this->assertNotSame($this->link, $c);
        $this->assertSame('1', mysql_result(mysql_query('SELECT 1', $c), 0));
    }

    public function testHostOnlyServerStringUsesDefaultPort(): void
    {
        $this->setIni('mysqli.default_port', (string) self::port());
        $link = mysql_connect(self::host(), self::user(), self::pass(), true);
        $this->assertInstanceOf(\mysqli::class, $link);
    }

    public function testClientFlagsArePassedThrough(): void
    {
        $link = mysql_connect(self::server(), self::user(), self::pass(), true, MYSQL_CLIENT_COMPRESS);
        $this->assertInstanceOf(\mysqli::class, $link);
        $row = mysql_fetch_row(mysql_query("SHOW SESSION STATUS LIKE 'Compression'", $link));
        if ($row === false) {
            $this->markTestSkipped('Server does not expose the Compression status variable');
        }
        $this->assertSame('ON', $row[1]);
    }

    public function testPconnectReturnsAWorkingDefaultLink(): void
    {
        $p = mysql_pconnect(self::server(), self::user(), self::pass());
        $this->assertInstanceOf(\mysqli::class, $p);
        $this->assertSame(mysqli_thread_id($p), mysql_thread_id());
        $this->assertSame('1', mysql_result(mysql_query('SELECT 1'), 0));
        $this->assertTrue(mysql_close($p));
    }

    public function testFailedConnectReturnsFalseWithWarning(): void
    {
        [$link, $errors] = $this->captureErrors(function () {
            return mysql_connect(self::server(), 'php7compat_no_such_user', 'wrong', true);
        });
        $this->assertFalse($link);
        $this->assertWarning($errors, 'mysql_connect(): Access denied');
    }

    public function testFailedConnectCanBeSilencedWithAtOperator(): void
    {
        [$link, $errors] = $this->captureErrors(function () {
            return @mysql_connect(self::server(), 'php7compat_no_such_user', 'wrong', true);
        });
        $this->assertFalse($link);
        $this->assertNoErrors($errors);
    }

    public function testFailedConnectDoesNotThrowWhenMysqliExceptionsAreEnabled(): void
    {
        $previous = (new \mysqli_driver())->report_mode;
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $link = @mysql_connect(self::server(), 'php7compat_no_such_user', 'wrong', true);
            $this->assertFalse($link);
            $this->assertSame(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT, (new \mysqli_driver())->report_mode);
        } finally {
            mysqli_report($previous);
        }
    }

    /**
     * The classic `mysql_connect(...) or die(mysql_error())` idiom: with no
     * default link, mysql_error()/mysql_errno() report the connection failure.
     */
    public function testErrorFunctionsReportConnectFailureWhenThereIsNoLink(): void
    {
        $this->closeAllLinks();
        $this->assertFalse(@mysql_connect(self::server(), 'php7compat_no_such_user', 'wrong'));
        $this->assertStringContainsString('Access denied', mysql_error());
        $this->assertSame(1045, mysql_errno());
    }

    public function testErrorFunctionsReturnFalseWithNoLinkAndNoConnectError(): void
    {
        $this->closeAllLinks();
        $this->assertFalse(mysql_error());
        $this->assertFalse(mysql_errno());
    }

    /**
     * As in the original, a failed connect does not replace an existing
     * default link, so mysql_error() reports on that link instead.
     */
    public function testFailedConnectLeavesExistingDefaultLinkInPlace(): void
    {
        $this->assertFalse(@mysql_connect(self::server(), 'php7compat_no_such_user', 'wrong', true));
        $this->assertSame(mysqli_thread_id($this->link), mysql_thread_id());
        $this->assertSame('', mysql_error());
        $this->assertSame(0, mysql_errno());
    }

    public function testCloseWithoutArgumentClosesTheDefaultLink(): void
    {
        $this->assertTrue(mysql_close());
        $this->assertFalse(\Php7Compat::link_is_open($this->link));
    }

    public function testClosingTheDefaultLinkExplicitlyForgetsIt(): void
    {
        $this->useUnreachableServerAsDefault();
        $this->assertTrue(mysql_close($this->link));

        // No default link any more, so this tries (and fails) to auto-connect
        // rather than using the closed link.
        [$result, $errors] = $this->captureErrors(function () {
            return mysql_query('SELECT 1');
        });
        $this->assertFalse($result);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('mysql_query(): A link to the server could not be established', end($errors)[1]);
    }

    public function testClosingANonDefaultLinkKeepsTheDefault(): void
    {
        $second = mysql_connect(self::server(), self::user(), self::pass(), true);
        $this->assertTrue(mysql_close($this->link));
        $this->assertSame(mysqli_thread_id($second), mysql_thread_id());
    }

    /**
     * Matches the original: closing the default link does not promote another
     * open link to be the default.
     */
    public function testClosingTheDefaultDoesNotPromoteAnotherLink(): void
    {
        $this->useUnreachableServerAsDefault();
        $second = mysql_connect(self::server(), self::user(), self::pass(), true);
        $this->assertTrue(mysql_close($second));
        $this->assertTrue(\Php7Compat::link_is_open($this->link));
        $this->assertFalse(@mysql_query('SELECT 1'));
    }

    public function testCloseWithNoLinkWarns(): void
    {
        $this->closeAllLinks();
        [$result, $errors] = $this->captureErrors(function () {
            return mysql_close();
        });
        $this->assertFalse($result);
        $this->assertWarning($errors, 'mysql_close(): no MySQL-Link resource supplied');
    }

    public function testClosingAnAlreadyClosedLinkWarns(): void
    {
        $this->assertTrue(mysql_close($this->link));
        [$result, $errors] = $this->captureErrors(function () {
            return mysql_close($this->link);
        });
        $this->assertFalse($result);
        $this->assertWarningMatches($errors, '/^mysql_close\(\): \d+ is not a valid MySQL-Link resource$/');
    }

    public function testAutoConnectsUsingIniDefaultsWhenThereIsNoLink(): void
    {
        $this->closeAllLinks();
        $this->useTestServerAsDefault();

        [$result, $errors] = $this->captureErrors(function () {
            return mysql_query('SELECT 42');
        });
        $this->assertNoErrors($errors);
        $this->assertSame('42', mysql_result($result, 0));
        $this->assertIsInt(mysql_thread_id());
    }

    public function testFailedAutoConnectWarnsAndReturnsFalse(): void
    {
        $this->closeAllLinks();
        $this->useUnreachableServerAsDefault();

        [$result, $errors] = $this->captureErrors(function () {
            return mysql_select_db(self::dbName());
        });
        $this->assertFalse($result);
        $this->assertCount(2, $errors, json_encode($errors));
        $this->assertSame(E_USER_WARNING, $errors[0][0]);
        $this->assertStringStartsWith('mysql_select_db(): ', $errors[0][1]);
        $this->assertSame(E_USER_WARNING, $errors[1][0]);
        $this->assertSame('mysql_select_db(): A link to the server could not be established', $errors[1][1]);
    }

    public function testExplicitNullLinkWarnsAndReturnsNull(): void
    {
        [$result, $errors] = $this->captureErrors(function () {
            return mysql_query('SELECT 1', null);
        });
        $this->assertNull($result);
        $this->assertWarning($errors, 'mysql_query() expects parameter 2 to be resource, null given');
    }

    public function testExplicitFalseLinkWarnsAndReturnsNull(): void
    {
        [$result, $errors] = $this->captureErrors(function () {
            return mysql_affected_rows(false);
        });
        $this->assertNull($result);
        $this->assertWarning($errors, 'mysql_affected_rows() expects parameter 1 to be resource, boolean given');
    }

    public function testExplicitClosedLinkWarnsAndReturnsFalse(): void
    {
        $other = mysql_connect(self::server(), self::user(), self::pass(), true);
        mysql_close($other);
        [$result, $errors] = $this->captureErrors(function () use ($other) {
            return mysql_query('SELECT 1', $other);
        });
        $this->assertFalse($result);
        $this->assertWarningMatches($errors, '/^mysql_query\(\): \d+ is not a valid MySQL-Link resource$/');
    }

    public function testPing(): void
    {
        $this->assertTrue(mysql_ping());
        $this->assertTrue(mysql_ping($this->link));
    }

    public function testPingAfterServerSideDisconnectReturnsFalse(): void
    {
        $victim = mysql_connect(self::server(), self::user(), self::pass(), true);
        $id = mysql_thread_id($victim);
        $this->assertTrue(mysql_query("KILL $id", $this->link));
        [$result] = $this->captureErrors(function () use ($victim) {
            return mysql_ping($victim);
        });
        $this->assertFalse($result);
    }

    public function testIncludingTheLibraryAgainKeepsTheDefaultLink(): void
    {
        $before = mysql_thread_id();
        require dirname(__DIR__, 2) . '/php7-compat.php';
        $this->assertSame($before, mysql_thread_id());
    }
}
