<?php

namespace Php7Compat\Tests\Ereg;

use Php7Compat\Tests\TestCase;

/**
 * ereg_replace() / eregi_replace().
 */
class EregReplaceTest extends TestCase
{
    public function testReplacesAllMatches(): void
    {
        $this->assertSame('aXcX', ereg_replace('b', 'X', 'abcb'));
    }

    public function testBackReferences(): void
    {
        $this->assertSame('ba', ereg_replace('(a)(b)', '\\2\\1', 'ab'));
        $this->assertSame('[ab]', ereg_replace('ab', '[\\0]', 'ab'));
    }

    public function testBackReferenceBeyondGroupCountIsLiteral(): void
    {
        $this->assertSame('\\3', ereg_replace('(a)(b)', '\\3', 'ab'));
    }

    public function testDollarIsLiteralInReplacement(): void
    {
        $this->assertSame('$1 costs $5', ereg_replace('(x)', '$1', 'x costs $5'));
    }

    public function testOtherBackslashesAreLiteralInReplacement(): void
    {
        $this->assertSame('a\\nb', ereg_replace('X', '\\n', 'aXb'));
        $this->assertSame('a\\\\b', ereg_replace('X', '\\\\', 'aXb'));
    }

    public function testUnmatchedGroupIsEmpty(): void
    {
        $this->assertSame('[]', ereg_replace('(a)(x)?', '[\\2]', 'a'));
    }

    public function testEmptyMatches(): void
    {
        $this->assertSame('-a-b-c-', ereg_replace('x*', '-', 'abc'));
        $this->assertSame('-a--c-', ereg_replace('b*', '-', 'abc'));
    }

    public function testCaretOnlyMatchesAtStart(): void
    {
        $this->assertSame('Xaa', ereg_replace('^a', 'X', 'aaa'));
    }

    public function testNoMatchReturnsStringUnchanged(): void
    {
        $this->assertSame('abc', ereg_replace('x', 'y', 'abc'));
    }

    public function testNonStringReplacementIsTreatedAsCharacterCode(): void
    {
        $this->assertSame('B', ereg_replace('a', 66, 'a'));
    }

    public function testEregiReplace(): void
    {
        $this->assertSame('aXc', eregi_replace('B', 'X', 'abc'));
        $this->assertSame('abc', ereg_replace('B', 'X', 'abc'));
    }

    public function testPosixSyntaxIsUsed(): void
    {
        $this->assertSame('a-b', ereg_replace('[[:space:]]+', '-', "a \t\n b"));
        $this->assertSame('x5', ereg_replace('\d', 'x', 'd5'));
    }

    public function testNulByteEndsTheStringPatternAndReplacement(): void
    {
        $this->assertSame('Xb', ereg_replace('a', 'X', "ab\0a"), 'Everything after a NUL byte is lost');
        $this->assertSame('X', ereg_replace('a', "X\0Y", 'a'));
        $this->assertSame('XbX', ereg_replace("a\0b", 'X', 'aba'));
    }

    public function testInvalidPatternWarnsAndReturnsFalse(): void
    {
        [$result, $errors] = $this->captureErrors(function () {
            return ereg_replace('(', 'x', 'abc');
        });
        $this->assertFalse($result);
        $this->assertWarning($errors, 'ereg_replace(): REG_EPAREN');
    }
}
