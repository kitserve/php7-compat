<?php

namespace Php7Compat\Tests;

/**
 * Base test case with helpers for asserting on warnings and notices.
 *
 * The original mysql_* and ereg* functions reported many problems as
 * E_WARNING / E_NOTICE while still returning a value. The wrappers emulate
 * this with E_USER_WARNING / E_USER_NOTICE, so tests need to capture errors
 * without aborting the call.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * Run $callback, collecting any PHP errors it raises instead of letting
     * PHPUnit turn them into exceptions. Errors suppressed with @ are ignored,
     * just as PHP's default handler would ignore them.
     *
     * @return array{0: mixed, 1: list<array{0: int, 1: string}>}
     */
    protected function captureErrors(callable $callback): array
    {
        $errors = [];
        set_error_handler(function (int $errno, string $errstr) use (&$errors): bool {
            if (error_reporting() & $errno) {
                $errors[] = [$errno, $errstr];
            }
            return true;
        });
        try {
            $result = $callback();
        } finally {
            restore_error_handler();
        }
        return [$result, $errors];
    }

    /**
     * Assert that exactly one error of the given level was raised and that
     * its message contains $needle.
     *
     * @param list<array{0: int, 1: string}> $errors
     */
    protected function assertSingleError(array $errors, int $level, string $needle): void
    {
        $this->assertCount(1, $errors, 'Expected exactly one error, got: ' . json_encode($errors));
        $this->assertSame($level, $errors[0][0], 'Unexpected error level for: ' . $errors[0][1]);
        $this->assertStringContainsString($needle, $errors[0][1]);
    }

    /**
     * @param list<array{0: int, 1: string}> $errors
     */
    protected function assertWarning(array $errors, string $needle): void
    {
        $this->assertSingleError($errors, E_USER_WARNING, $needle);
    }

    /**
     * Assert that exactly one E_USER_WARNING was raised, matching $pattern.
     *
     * @param list<array{0: int, 1: string}> $errors
     */
    protected function assertWarningMatches(array $errors, string $pattern): void
    {
        $this->assertCount(1, $errors, 'Expected exactly one error, got: ' . json_encode($errors));
        $this->assertSame(E_USER_WARNING, $errors[0][0], 'Unexpected error level for: ' . $errors[0][1]);
        $this->assertMatchesRegularExpression($pattern, $errors[0][1]);
    }

    /**
     * @param list<array{0: int, 1: string}> $errors
     */
    protected function assertNoErrors(array $errors): void
    {
        $this->assertSame([], $errors, 'Unexpected errors: ' . json_encode($errors));
    }
}
