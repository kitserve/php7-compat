<?php

namespace Php7Compat\Tests\Misc;

use Php7Compat\Tests\TestCase;

/**
 * Argument count and type checking, matching PHP 5's checks for built-in
 * functions. The mysql_* equivalents are in Mysql\ArgumentTest.
 */
class ArgumentTest extends TestCase
{
    /**
     * @dataProvider badCalls
     */
    public function testBadArgumentsWarnAndReturnNull(string $function, array $args, string $message): void
    {
        [$result, $errors] = $this->captureErrors(function () use ($function, $args) {
            return $function(...$args);
        });
        $this->assertNull($result);
        $this->assertSame([[E_USER_WARNING, $message]], $errors);
    }

    public function badCalls(): array
    {
        return [
            'too many' => ['mysql_escape_string', ['a', 'b'], 'mysql_escape_string() expects exactly 1 parameter, 2 given'],
            'too few' => ['mysql_escape_string', [], 'mysql_escape_string() expects exactly 1 parameter, 0 given'],
            'at least' => ['ereg', ['a'], 'ereg() expects at least 2 parameters, 1 given'],
            'at most' => ['ereg', ['a', 'b', null, 'd'], 'ereg() expects at most 3 parameters, 4 given'],
            'exactly 3' => ['ereg_replace', ['a', 'b'], 'ereg_replace() expects exactly 3 parameters, 2 given'],
            'none expected' => ['mysql_get_client_info', [1], 'mysql_get_client_info() expects exactly 0 parameters, 1 given'],
            'string' => ['split', [',', ['a']], 'split() expects parameter 2 to be string, array given'],
            'long' => ['split', [',', 'a,b', 'many'], 'split() expects parameter 3 to be long, string given'],
            'long from array' => ['split', [',', 'a,b', []], 'split() expects parameter 3 to be long, array given'],
            'boolean' => ['set_magic_quotes_runtime', [[]], 'set_magic_quotes_runtime() expects parameter 1 to be boolean, array given'],
            'object without __toString' => ['sql_regcase', [new \stdClass()], 'sql_regcase() expects parameter 1 to be string, object given'],
            'resource' => ['mysql_num_rows', [false], 'mysql_num_rows() expects parameter 1 to be resource, boolean given'],
            'resource from object' => ['mysql_num_rows', [new \stdClass()], 'mysql_num_rows() expects parameter 1 to be resource, object given'],
            'resource from null' => ['mysql_free_result', [null], 'mysql_free_result() expects parameter 1 to be resource, null given'],
            'resource from integer' => ['mysql_num_fields', [1], 'mysql_num_fields() expects parameter 1 to be resource, integer given'],
            'ping with two' => ['mysql_ping', [1, 2], 'mysql_ping() expects exactly 1 parameter, 2 given'],
        ];
    }

    public function testScalarsAreAcceptedForStrings(): void
    {
        $this->assertSame('[Tt][Rr][Uu][Ee]', sql_regcase('true'));
        $this->assertSame('1', sql_regcase(true));
        $this->assertSame('', sql_regcase(null));
        $this->assertSame('12.5', sql_regcase(12.5));
    }

    public function testObjectWithToStringIsAcceptedForStrings(): void
    {
        $this->assertSame('[Hh][Ii]', sql_regcase(new ArgumentTestStringable()));
    }

    public function testNumericStringsAreAcceptedForLongs(): void
    {
        $this->assertSame(['a', 'b,c'], split(',', 'a,b,c', '2'));
        $this->assertSame(['a', 'b,c'], split(',', 'a,b,c', ' 2'));
        $this->assertSame(['a', 'b,c'], split(',', 'a,b,c', 2.7));
    }

    public function testLeadingNumericStringIsAcceptedWithNotice(): void
    {
        [$result, $errors] = $this->captureErrors(function () {
            return split(',', 'a,b,c', '2 pieces');
        });
        $this->assertSame(['a', 'b,c'], $result);
        $this->assertSingleError($errors, E_USER_NOTICE, 'A non well formed numeric value encountered');
    }

    public function testNullAllowedWhereSpecified(): void
    {
        $this->assertNull(@mysql_connect(null, null, null, false, 'x'), 'Only the client flags are checked here');
    }

    public function testDeprecatedFunctionNoticeComesBeforeArgumentWarning(): void
    {
        \Php7Compat::$deprecations = true;
        try {
            [, $errors] = $this->captureErrors(function () {
                return ereg('a');
            });
        } finally {
            \Php7Compat::$deprecations = false;
        }
        $this->assertSame([
            [E_USER_DEPRECATED, 'Function ereg() is deprecated'],
            [E_USER_WARNING, 'ereg() expects at least 2 parameters, 1 given'],
        ], $errors);
    }
}

class ArgumentTestStringable
{
    public function __toString()
    {
        return 'hi';
    }
}
