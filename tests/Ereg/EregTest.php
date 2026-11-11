<?php

namespace Php7Compat\Tests\Ereg;

use Php7Compat\Tests\TestCase;

/**
 * ereg() / eregi(): return values, $regs population, and POSIX ERE syntax.
 */
class EregTest extends TestCase
{
    public function testReturnsOneOnMatchWithoutRegs(): void
    {
        $this->assertSame(1, ereg('b+', 'abbbc'));
    }

    public function testReturnsFalseOnNoMatch(): void
    {
        $this->assertFalse(ereg('x', 'abc'));
        $this->assertFalse(ereg('x', 'abc', $regs));
    }

    public function testReturnsMatchLengthAndFillsRegs(): void
    {
        $this->assertSame(3, ereg('(a)(b)?c', 'xxabcxx', $regs));
        $this->assertSame(['abc', 'a', 'b'], $regs);
    }

    public function testUnmatchedGroupsAreFalse(): void
    {
        $this->assertSame(2, ereg('(a)(b)?c', 'xac', $regs));
        $this->assertSame(['ac', 'a', false], $regs);
    }

    public function testTrailingUnmatchedGroupsAreStillPresent(): void
    {
        $this->assertSame(1, ereg('(a)(x)?(y)?', 'a', $regs));
        $this->assertSame(['a', 'a', false, false], $regs);
    }

    public function testEmptyGroupMatchIsFalse(): void
    {
        $this->assertSame(2, ereg('a(b*)c', 'ac', $regs));
        $this->assertSame(['ac', false], $regs);
    }

    public function testEmptyMatchReturnsOne(): void
    {
        $this->assertSame(1, ereg('x*', 'abc', $regs));
        $this->assertSame([false], $regs);
    }

    public function testRegsIsReplacedOnMatch(): void
    {
        $regs = ['stale', 'values', 'here', 'too'];
        ereg('(b)', 'abc', $regs);
        $this->assertSame(['b', 'b'], $regs);
    }

    public function testRegsIsLeftAloneOnNoMatch(): void
    {
        $regs = 'unchanged';
        $this->assertFalse(ereg('x', 'abc', $regs));
        $this->assertSame('unchanged', $regs);
    }

    public function testEregiIsCaseInsensitive(): void
    {
        $this->assertSame(3, eregi('ABC', 'xabcx', $regs));
        $this->assertSame(['abc'], $regs);
        $this->assertFalse(ereg('ABC', 'xabcx'));
    }

    public function testBackslashMakesAnyCharacterLiteral(): void
    {
        // POSIX ERE has no \d, \w, \s etc: a backslash just makes the next character literal.
        $this->assertFalse(ereg('\d', '5'));
        $this->assertSame(1, ereg('^\d$', 'd'));
        $this->assertSame(1, ereg('^\w\s$', 'ws'));
        $this->assertSame(1, ereg('^a\.b$', 'a.b'));
        $this->assertFalse(ereg('^a\.b$', 'axb'));
        $this->assertSame(1, ereg('^\1$', '1'), 'No back-references in ERE');
    }

    public function testDotMatchesNewline(): void
    {
        $this->assertSame(1, ereg('^a.b$', "a\nb"));
    }

    public function testDollarOnlyMatchesAtTheVeryEnd(): void
    {
        $this->assertFalse(ereg('a$', "a\n"));
        $this->assertSame(1, ereg('a$', 'a'));
    }

    public function testDollarCanBeRepeated(): void
    {
        $this->assertSame(1, ereg('a$*', 'ab'));
        $this->assertSame(1, ereg('^a$+', 'a'));
        $this->assertFalse(ereg('^a$+', 'ab'));
    }

    public function testCaretDoesNotMatchAfterNewline(): void
    {
        $this->assertFalse(ereg('^b', "a\nb"));
    }

    public function testBackslashIsLiteralInsideBrackets(): void
    {
        $this->assertSame(1, ereg('[\]', 'a\\b'));
        $this->assertSame(1, ereg('^[\n]+$', '\\n\\'));
        $this->assertFalse(ereg('[\n]', "\n"));
    }

    public function testCloseBracketFirstInBracketIsLiteral(): void
    {
        $this->assertSame(1, ereg('^[]a]+$', ']a]'));
        $this->assertSame(1, ereg('^[^]a]$', 'b'));
        $this->assertFalse(ereg('^[^]a]$', ']'));
    }

    public function testRangesAndHyphens(): void
    {
        $this->assertSame(1, ereg('^[a-c]+$', 'abcab'));
        $this->assertSame(1, ereg('^[a-]+$', 'a-a'));
        $this->assertSame(1, ereg('^[-a]+$', '-a-'));
        $this->assertSame(1, ereg('^[+--]+$', '+,-'), 'Range from + to -');
    }

    public function testCharacterClasses(): void
    {
        $this->assertSame(1, ereg('^[[:digit:]]+$', '0123'));
        $this->assertSame(1, ereg('^[[:alpha:][:space:]]+$', 'ab c'));
        $this->assertFalse(ereg('^[[:upper:]]+$', 'abc'));
        $this->assertSame(1, ereg('^[^[:digit:]]+$', 'abc'));
    }

    public function testEquivalenceAndCollatingElements(): void
    {
        $this->assertSame(1, ereg('^[[=a=]]$', 'a'));
        $this->assertSame(1, ereg('^[[.-.]a]+$', '-a'));
    }

    public function testNamedCollatingElements(): void
    {
        $this->assertSame(1, ereg('^[[.hyphen.][.space.]]+$', '- -'));
        $this->assertSame(1, ereg('^[[=hyphen=]]$', '-'), 'Equivalence classes accept names too');
        $this->assertSame(1, ereg('^[[.zero.]-[.nine.]]+$', '0459'), 'Named range ends');
        $this->assertSame(1, ereg('^[[.backslash.][.right-square-bracket.]]+$', '\\]'));
        $this->assertSame(1, ereg('^[[.tab.]]$', "\t"));
    }

    public function testHyphenMustBeAtTheEdgesOrInARange(): void
    {
        $this->assertSame(1, ereg('^[-a]+$', '-a'));
        $this->assertSame(1, ereg('^[a-]+$', 'a-'));
        $this->assertSame(1, ereg('^[!--]+$', '!,-'), 'Range ending in -');
        $this->assertSame(1, ereg('^[^-a]$', 'b'));
    }

    public function testMatchingStopsAtNulByte(): void
    {
        $this->assertFalse(ereg('b', "a\0b"));
        $this->assertSame(1, ereg('a', "a\0b", $regs));
        $this->assertSame(['a'], $regs);
        $this->assertSame(1, ereg("^a\0b", 'a'), 'The pattern is cut at a NUL byte too');
        $this->assertSame(1, ereg('^a$', "a\0"), 'The original saw just "a"');
    }

    public function testWordBoundaries(): void
    {
        $this->assertSame(1, ereg('[[:<:]]cat[[:>:]]', 'a cat here'));
        $this->assertFalse(ereg('[[:<:]]cat[[:>:]]', 'concatenate'));
        $this->assertFalse(ereg('[[:<:]]cat', 'concat'));
        $this->assertFalse(ereg('cat[[:>:]]', 'cats'));
    }

    public function testIntervals(): void
    {
        $this->assertSame(1, ereg('^a{2}$', 'aa'));
        $this->assertSame(1, ereg('^a{2,}$', 'aaaa'));
        $this->assertSame(1, ereg('^a{1,2}$', 'aa'));
        $this->assertFalse(ereg('^a{1,2}$', 'aaa'));
        $this->assertSame(1, ereg('^a{b$', 'a{b'), '{ not followed by a digit is literal');
        $this->assertSame(1, ereg('^}$', '}'));
    }

    public function testDelimiterAndSpecialCharactersAreSafe(): void
    {
        $this->assertSame(1, ereg('^a/b$', 'a/b'));
        $this->assertSame(1, ereg('^a\/b$', 'a/b'));
        $this->assertSame(1, ereg('^#~%@$', '#~%@'));
        $this->assertSame(1, ereg('[/]', '/'));
    }

    public function testEmptyGroupIsAllowed(): void
    {
        $this->assertSame(2, ereg('a()b', 'ab', $regs));
        $this->assertSame(['ab', false], $regs);
    }

    public function testAlternation(): void
    {
        $this->assertSame(3, ereg('cat|dog', 'hotdog', $regs));
        $this->assertSame(['dog'], $regs);
    }

    public function testNonStringPatternIsTreatedAsCharacterCode(): void
    {
        $this->assertSame(1, ereg(65, 'xAy'));
    }

    public function testStringArgumentIsCoerced(): void
    {
        $this->assertSame(1, ereg('^12$', 12));
    }

    /**
     * @dataProvider invalidPatterns
     */
    public function testInvalidPatternsWarnAndReturnFalse(string $pattern, string $code): void
    {
        [$result, $errors] = $this->captureErrors(function () use ($pattern) {
            return ereg($pattern, 'abc');
        });
        $this->assertFalse($result);
        $this->assertWarning($errors, "ereg(): $code");
    }

    public function invalidPatterns(): array
    {
        return [
            'empty pattern' => ['', 'REG_EMPTY'],
            'empty branch' => ['a|', 'REG_EMPTY'],
            'leading empty branch' => ['|a', 'REG_EMPTY'],
            'empty branch in group' => ['(a|)', 'REG_EMPTY'],
            'unclosed paren' => ['(a', 'REG_EPAREN'],
            'unmatched close paren' => ['a)', 'REG_EPAREN'],
            'unclosed bracket' => ['[a', 'REG_EBRACK'],
            'trailing backslash' => ['a\\', 'REG_EESCAPE'],
            'leading repetition' => ['*a', 'REG_BADRPT'],
            'double repetition' => ['a**', 'REG_BADRPT'],
            'lazy quantifier syntax' => ['a+?', 'REG_BADRPT'],
            'repeat caret' => ['^*a', 'REG_BADRPT'],
            'leading interval' => ['{1}a', 'REG_BADRPT'],
            'unclosed interval' => ['a{1', 'REG_EBRACE'],
            'bad interval' => ['a{3,1}', 'REG_BADBR'],
            'interval too large' => ['a{256}', 'REG_BADBR'],
            'unknown class' => ['[[:nope:]]', 'REG_ECTYPE'],
            'bad range' => ['[z-a]', 'REG_ERANGE'],
            'unknown collating element' => ['[[.nope.]]', 'REG_ECOLLATE'],
            'unknown equivalence class' => ['[[=nope=]]', 'REG_ECOLLATE'],
            'names are case-sensitive' => ['[[.HYPHEN.]]', 'REG_ECOLLATE'],
            'backwards range ending in hyphen' => ['[a--]', 'REG_ERANGE'],
            'hyphen in the middle' => ['[a-c-e]', 'REG_ERANGE'],
            'hyphen after leading ]' => ['[]-a]', 'REG_ERANGE'],
            'unterminated class name' => ['[[:alpha]]', 'REG_ECTYPE'],
            'empty class name' => ['[[:]]', 'REG_ECTYPE'],
            'empty equivalence class' => ['[[=]=]]', 'REG_ECOLLATE'],
            'unterminated collating element' => ['[[.a]', 'REG_EBRACK'],
        ];
    }

    public function testInvalidPatternWarningCanBeSilenced(): void
    {
        [$result, $errors] = $this->captureErrors(function () {
            return @ereg('(', 'abc');
        });
        $this->assertFalse($result);
        $this->assertNoErrors($errors);
    }
}
