<?php

namespace Php7Compat\Tests\Ereg;

use Php7Compat\Tests\TestCase;

/**
 * split(), spliti() and sql_regcase().
 */
class SplitTest extends TestCase
{
    public function testSplitsOnPattern(): void
    {
        $this->assertSame(['a', 'b', 'c'], split(',', 'a,b,c'));
        $this->assertSame(['2020', '01', '02'], split('[/.-]', '2020/01-02'));
    }

    public function testLimit(): void
    {
        $this->assertSame(['a', 'b,c'], split(',', 'a,b,c', 2));
        $this->assertSame(['a,b,c'], split(',', 'a,b,c', 1));
        $this->assertSame(['a,b,c'], split(',', 'a,b,c', 0));
        $this->assertSame(['a,b,c'], split(',', 'a,b,c', -2));
        $this->assertSame(['a', 'b', 'c'], split(',', 'a,b,c', -1));
    }

    public function testLeadingAndTrailingDelimiters(): void
    {
        $this->assertSame(['', 'a'], split(',', ',a'));
        $this->assertSame(['a', ''], split(',', 'a,'));
        $this->assertSame([''], split(',', ''));
    }

    public function testCaretMatchesAtStartOfEachRemainingPiece(): void
    {
        // The original ran the regex on the remainder of the string each time.
        $this->assertSame(['', '', 'b'], split('^a', 'aab'));
    }

    public function testEscapedPipe(): void
    {
        $this->assertSame(['a', 'b'], split('\|', 'a|b'));
    }

    public function testPatternThatMatchesEmptyStringFails(): void
    {
        [$result, $errors] = $this->captureErrors(function () {
            return split('x*', 'abc');
        });
        $this->assertFalse($result);
        $this->assertWarning($errors, 'split(): Invalid Regular Expression');
    }

    public function testEmptyMatchAtEndFails(): void
    {
        [$result, $errors] = $this->captureErrors(function () {
            return split('$', 'abc');
        });
        $this->assertFalse($result);
        $this->assertWarning($errors, 'split(): Invalid Regular Expression');
    }

    public function testSearchStopsAtNulByteButLastPieceKeepsTheRest(): void
    {
        $this->assertSame(['a', "b\0,c"], split(',', "a,b\0,c"));
        $this->assertSame(["\0,a"], split(',', "\0,a"));
    }

    public function testSpliti(): void
    {
        $this->assertSame(['a', 'b', 'c'], spliti('X', 'axbXc'));
        $this->assertSame(['axb', 'c'], split('X', 'axbXc'));
    }

    public function testInvalidPattern(): void
    {
        [$result, $errors] = $this->captureErrors(function () {
            return split('[', 'abc');
        });
        $this->assertFalse($result);
        $this->assertWarning($errors, 'split(): REG_EBRACK');
    }

    public function testSqlRegcase(): void
    {
        $this->assertSame('[Ff][Oo][Oo] [Bb][Aa][Rr]', sql_regcase('Foo bar'));
        $this->assertSame('1-2_[Xx]', sql_regcase('1-2_x'));
    }
}
