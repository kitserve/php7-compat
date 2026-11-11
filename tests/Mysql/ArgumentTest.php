<?php

namespace Php7Compat\Tests\Mysql;

/**
 * Argument checking for the mysql_* functions, and aliases naming
 * themselves in messages.
 */
class ArgumentTest extends MysqlTestCase
{
    public function testTooFewArguments(): void
    {
        [$result, $errors] = $this->captureErrors(function () {
            return mysql_query();
        });
        $this->assertNull($result);
        $this->assertSame([[E_USER_WARNING, 'mysql_query() expects at least 1 parameter, 0 given']], $errors);
    }

    public function testTooManyArguments(): void
    {
        [$result, $errors] = $this->captureErrors(function () {
            return mysql_query('SELECT 1', $this->link, 'extra');
        });
        $this->assertNull($result);
        $this->assertSame([[E_USER_WARNING, 'mysql_query() expects at most 2 parameters, 3 given']], $errors);
    }

    public function testArrayAsQuery(): void
    {
        [$result, $errors] = $this->captureErrors(function () {
            return mysql_query(['SELECT 1']);
        });
        $this->assertNull($result);
        $this->assertSame([[E_USER_WARNING, 'mysql_query() expects parameter 1 to be string, array given']], $errors);
    }

    public function testObjectAsLink(): void
    {
        [$result, $errors] = $this->captureErrors(function () {
            return mysql_query('SELECT 1', new \stdClass());
        });
        $this->assertNull($result);
        $this->assertSame([[E_USER_WARNING, 'mysql_query() expects parameter 2 to be resource, object given']], $errors);
    }

    public function testOtherResourceAsLink(): void
    {
        $handle = fopen('php://memory', 'r');
        [$result, $errors] = $this->captureErrors(function () use ($handle) {
            return mysql_query('SELECT 1', $handle);
        });
        fclose($handle);
        $this->assertFalse($result);
        $this->assertWarning($errors, 'mysql_query(): supplied resource is not a valid MySQL-Link resource');
    }

    public function testResultAsLink(): void
    {
        [$result, $errors] = $this->captureErrors(function () {
            return mysql_affected_rows(mysql_query('SELECT 1'));
        });
        $this->assertFalse($result);
        $this->assertWarning($errors, 'mysql_affected_rows(): supplied resource is not a valid MySQL-Link resource');
    }

    public function testNonNumericOffset(): void
    {
        $result = mysql_query('SELECT 1');
        [$value, $errors] = $this->captureErrors(function () use ($result) {
            return mysql_data_seek($result, 'first');
        });
        $this->assertNull($value);
        $this->assertSame([[E_USER_WARNING, 'mysql_data_seek() expects parameter 2 to be long, string given']], $errors);
    }

    public function testNumericStringOffset(): void
    {
        $result = mysql_query('SELECT id FROM compat_people ORDER BY id');
        $this->assertTrue(mysql_data_seek($result, '1'));
        $this->assertSame(['2'], mysql_fetch_row($result));
        $this->assertSame('3', mysql_result($result, '2'));
    }

    public function testConnectChecksArguments(): void
    {
        [$result, $errors] = $this->captureErrors(function () {
            return mysql_connect(self::server(), self::user(), self::pass(), false, 0, 'extra');
        });
        $this->assertNull($result);
        $this->assertSame([[E_USER_WARNING, 'mysql_connect() expects at most 5 parameters, 6 given']], $errors);

        [$result, $errors] = $this->captureErrors(function () {
            return mysql_pconnect(self::server(), self::user(), self::pass(), 0, true);
        });
        $this->assertNull($result);
        $this->assertSame([[E_USER_WARNING, 'mysql_pconnect() expects at most 4 parameters, 5 given']], $errors);
    }

    public function testPingWithNoArgumentsUsesTheDefaultLink(): void
    {
        $this->assertTrue(mysql_ping());
    }

    public function testFetchFunctionsCheckArguments(): void
    {
        $result = mysql_query('SELECT 1');
        foreach (['mysql_fetch_row', 'mysql_fetch_assoc', 'mysql_fetch_array'] as $function) {
            [$value, $errors] = $this->captureErrors(function () use ($function, $result) {
                return $function($result, MYSQL_NUM, 'extra');
            });
            $this->assertNull($value, $function);
            $this->assertSame([[E_USER_WARNING, "$function() expects at most 2 parameters, 3 given"]], $errors);
        }
        [$value, $errors] = $this->captureErrors(function () use ($result) {
            return mysql_fetch_object($result, []);
        });
        $this->assertNull($value);
        $this->assertSame([[E_USER_WARNING, 'mysql_fetch_object() expects parameter 2 to be string, array given']], $errors);
    }

    public function testAliasesUseTheirOwnNameInMessages(): void
    {
        $result = mysql_query('SELECT 1');
        [$value, $errors] = $this->captureErrors(function () use ($result) {
            return mysql_fieldname($result, 9);
        });
        $this->assertFalse($value);
        $this->assertWarning($errors, 'mysql_fieldname(): Field 9 is invalid for MySQL result index');

        [$value, $errors] = $this->captureErrors(function () {
            return mysql_numrows();
        });
        $this->assertNull($value);
        $this->assertSame([[E_USER_WARNING, 'mysql_numrows() expects exactly 1 parameter, 0 given']], $errors);
    }

    public function testNameReturnsToNormalAfterAnAlias(): void
    {
        mysql_numrows(mysql_query('SELECT 1'));
        [, $errors] = $this->captureErrors(function () {
            return mysql_num_rows();
        });
        $this->assertSame([[E_USER_WARNING, 'mysql_num_rows() expects exactly 1 parameter, 0 given']], $errors);
    }
}
