<?php

namespace Php7Compat\Tests\Misc;

use Php7Compat\Tests\TestCase;

/**
 * Deprecation notices for functions that need no database, and the
 * PHP7COMPAT_NO_DEPRECATIONS opt-out.
 */
class DeprecationTest extends TestCase
{
    protected function setUp(): void
    {
        \Php7Compat::$deprecations = true;
    }

    protected function tearDown(): void
    {
        \Php7Compat::$deprecations = false;
    }

    /**
     * @return list<array{0: int, 1: string}>
     */
    private function errorsFrom(callable $callback): array
    {
        return $this->captureErrors($callback)[1];
    }

    /**
     * @dataProvider eregCalls
     */
    public function testEregFunctionsAreDeprecated(string $function, array $args): void
    {
        $errors = $this->errorsFrom(function () use ($function, $args) {
            return $function(...$args);
        });
        $this->assertSingleError($errors, E_USER_DEPRECATED, "Function $function() is deprecated");
        $this->assertSame("Function $function() is deprecated", $errors[0][1]);
    }

    public function eregCalls(): array
    {
        return [
            ['ereg', ['a', 'abc']],
            ['eregi', ['a', 'abc']],
            ['ereg_replace', ['a', 'b', 'abc']],
            ['eregi_replace', ['a', 'b', 'abc']],
            ['split', [',', 'a,b']],
            ['spliti', [',', 'a,b']],
            ['sql_regcase', ['abc']],
        ];
    }

    public function testDeprecationNoticeComesBeforeOtherErrors(): void
    {
        $errors = $this->errorsFrom(function () {
            return ereg('(', 'abc');
        });
        $this->assertCount(2, $errors);
        $this->assertSame([E_USER_DEPRECATED, 'Function ereg() is deprecated'], $errors[0]);
        $this->assertSame([E_USER_WARNING, 'ereg(): REG_EPAREN'], $errors[1]);
    }

    public function testEscapeStringIsDeprecated(): void
    {
        $errors = $this->errorsFrom(function () {
            return mysql_escape_string("'");
        });
        $this->assertSame([[E_USER_DEPRECATED, 'mysql_escape_string(): This function is deprecated; use mysql_real_escape_string() instead.']], $errors);
    }

    public function testSetMagicQuotesRuntimeIsDeprecated(): void
    {
        $this->assertSame(
            [[E_USER_DEPRECATED, 'Function set_magic_quotes_runtime() is deprecated']],
            $this->errorsFrom(function () {
                return set_magic_quotes_runtime(false);
            })
        );
        $this->assertSame(
            [
                [E_USER_DEPRECATED, 'Function magic_quotes_runtime() is deprecated'],
                [E_USER_WARNING, 'magic_quotes_runtime(): magic_quotes_runtime is not supported anymore'],
            ],
            $this->errorsFrom(function () {
                return magic_quotes_runtime(true);
            })
        );
    }

    public function testNonDeprecatedFunctionsRaiseNoNotice(): void
    {
        $this->assertNoErrors($this->errorsFrom(function () {
            return is_mysql_resource(null);
        }));
    }

    public function testNoticesCanBeSilencedWithAtOperator(): void
    {
        $this->assertNoErrors($this->errorsFrom(function () {
            return @ereg('a', 'abc');
        }));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testNoticesAreOnByDefault(): void
    {
        \Php7Compat::$deprecations = null;
        $this->assertSingleError($this->errorsFrom(function () {
            return ereg('a', 'abc');
        }), E_USER_DEPRECATED, 'Function ereg() is deprecated');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testConstantSwitchesNoticesOff(): void
    {
        \Php7Compat::$deprecations = null;
        define('PHP7COMPAT_NO_DEPRECATIONS', true);
        $this->assertNoErrors($this->errorsFrom(function () {
            return ereg('a', 'abc');
        }));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testConstantSetToFalseLeavesNoticesOn(): void
    {
        \Php7Compat::$deprecations = null;
        define('PHP7COMPAT_NO_DEPRECATIONS', false);
        $this->assertSingleError($this->errorsFrom(function () {
            return ereg('a', 'abc');
        }), E_USER_DEPRECATED, 'Function ereg() is deprecated');
    }
}
