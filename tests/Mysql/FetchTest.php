<?php

namespace Php7Compat\Tests\Mysql;

/**
 * Row fetching: mysql_fetch_*, mysql_result(), mysql_data_seek(),
 * mysql_num_rows/fields(), mysql_fetch_lengths(), mysql_free_result().
 */
class FetchTest extends MysqlTestCase
{
    private function people()
    {
        return mysql_query('SELECT id, name, email, score FROM compat_people ORDER BY id');
    }

    public function testFetchAssocReturnsFalseNotNullAfterLastRow(): void
    {
        $result = $this->people();
        $count = 0;
        // This loop never terminates if the wrapper passes on mysqli's null at the end of the rows.
        while (($row = mysql_fetch_assoc($result)) !== false) {
            $this->assertIsArray($row);
            if (++$count > 10) {
                $this->fail('mysql_fetch_assoc() never returned false');
            }
        }
        $this->assertSame(3, $count);
        $this->assertFalse(mysql_fetch_assoc($result));
    }

    public function testEveryFetchFunctionReturnsFalseAtEnd(): void
    {
        $result = mysql_query('SELECT 1 FROM compat_people WHERE 0 = 1');
        $this->assertFalse(mysql_fetch_assoc($result));
        $this->assertFalse(mysql_fetch_row($result));
        $this->assertFalse(mysql_fetch_array($result));
        $this->assertFalse(mysql_fetch_object($result));
        $this->assertFalse(mysql_fetch_lengths($result));
    }

    public function testValuesAreStringsAndNullIsNull(): void
    {
        $row = mysql_fetch_assoc(mysql_query('SELECT id, name, email, score FROM compat_people WHERE id = 2'));
        $this->assertSame(['id' => '2', 'name' => 'Bob', 'email' => null, 'score' => '7.25'], $row);
    }

    public function testFetchRow(): void
    {
        $this->assertSame(['1', 'Alice', 'alice@example.com', '9.50'], mysql_fetch_row($this->people()));
    }

    public function testFetchArrayResultTypes(): void
    {
        $result = $this->people();
        $both = mysql_fetch_array($result);
        $this->assertSame(['1', '1', 'Alice', 'Alice'], [$both[0], $both['id'], $both[1], $both['name']]);
        $this->assertCount(8, $both);

        $this->assertSame(['2', 'Bob', null, '7.25'], mysql_fetch_array($result, MYSQL_NUM));
        $this->assertSame(
            ['id' => '3', 'name' => 'Carol', 'email' => 'carol@example.com', 'score' => '8.00'],
            mysql_fetch_array($result, MYSQL_ASSOC)
        );
    }

    public function testFetchArrayTreatsZeroAsBoth(): void
    {
        [$row, $errors] = $this->captureErrors(function () {
            return mysql_fetch_array($this->people(), 0);
        });
        $this->assertNoErrors($errors);
        $this->assertSame(['1', '1'], [$row[0], $row['id']]);
    }

    public function testFetchArrayWarnsAboutInvalidResultTypeButStillFetches(): void
    {
        [$row, $errors] = $this->captureErrors(function () {
            return mysql_fetch_array($this->people(), 99);
        });
        $this->assertWarning($errors, 'mysql_fetch_array(): The result type should be either MYSQL_NUM, MYSQL_ASSOC or MYSQL_BOTH');
        $this->assertSame(['1', '1'], [$row[0], $row['id']]);
    }

    public function testFetchRowAndAssocAcceptAResultType(): void
    {
        $result = $this->people();
        $this->assertSame(['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com', 'score' => '9.50'], mysql_fetch_row($result, MYSQL_ASSOC));
        $this->assertSame(['2', 'Bob', null, '7.25'], mysql_fetch_assoc($result, MYSQL_NUM));
        $this->assertCount(8, mysql_fetch_assoc($result, 0), '0 means MYSQL_BOTH');
    }

    public function testFetchObjectOnFailedQueryWarnsAndReturnsFalse(): void
    {
        // Unlike the other fetch functions, the original accepted any value here.
        [$row, $errors] = $this->captureErrors(function () {
            return mysql_fetch_object(false);
        });
        $this->assertFalse($row);
        $this->assertWarning($errors, 'mysql_fetch_object(): supplied argument is not a valid MySQL result resource');
    }

    public function testFetchObjectConstructorArgsWithoutConstructorThrows(): void
    {
        $result = $this->people();
        try {
            mysql_fetch_object($result, 'stdClass', ['x']);
            $this->fail('Expected an exception');
        } catch (\Exception $e) {
            $this->assertSame(\Exception::class, get_class($e));
            $this->assertSame('Class stdClass does not have a constructor hence you cannot use ctor_params', $e->getMessage());
        }
        $this->assertSame('Bob', mysql_fetch_assoc($result)['name'], 'The row was still consumed');
    }

    public function testFetchObjectNonArrayConstructorArgsThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Parameter ctor_params must be an array');
        mysql_fetch_object($this->people(), FetchTestPerson::class, 'hello');
    }

    public function testFetchObjectWithNullConstructorArgs(): void
    {
        $obj = mysql_fetch_object($this->people(), FetchTestPerson::class, null);
        $this->assertSame('Alice', $obj->name);
        $this->assertNull($obj->greeting);
    }

    public function testWrongKindOfObjectIsRejected(): void
    {
        [$value, $errors] = $this->captureErrors(function () {
            return mysql_num_rows($this->link);
        });
        $this->assertFalse($value);
        $this->assertWarning($errors, 'mysql_num_rows(): supplied resource is not a valid MySQL result resource');
    }

    public function testFetchObject(): void
    {
        $obj = mysql_fetch_object($this->people());
        $this->assertInstanceOf(\stdClass::class, $obj);
        $this->assertSame('Alice', $obj->name);
    }

    public function testFetchObjectWithClassAndConstructorArgs(): void
    {
        $obj = mysql_fetch_object($this->people(), FetchTestPerson::class, ['hello']);
        $this->assertInstanceOf(FetchTestPerson::class, $obj);
        $this->assertSame('Alice', $obj->name);
        $this->assertSame('hello', $obj->greeting);
    }

    public function testFetchLengths(): void
    {
        $result = $this->people();
        mysql_fetch_row($result);
        $this->assertSame([1, 5, 17, 4], mysql_fetch_lengths($result));
    }

    public function testFetchOnFailedQueryWarnsAndReturnsNull(): void
    {
        $result = mysql_query('SELEC nonsense');
        [$row, $errors] = $this->captureErrors(function () use ($result) {
            return mysql_fetch_assoc($result);
        });
        $this->assertNull($row);
        $this->assertWarning($errors, 'mysql_fetch_assoc() expects parameter 1 to be resource, boolean given');
    }

    public function testWhileLoopOverFailedQueryTerminates(): void
    {
        $result = mysql_query('SELEC nonsense');
        $count = 0;
        while ($row = @mysql_fetch_array($result)) {
            $count++;
        }
        $this->assertSame(0, $count);
    }

    public function testFetchOnFreedResultWarnsAndReturnsFalse(): void
    {
        $result = $this->people();
        mysql_free_result($result);
        [$row, $errors] = $this->captureErrors(function () use ($result) {
            return mysql_fetch_row($result);
        });
        $this->assertFalse($row);
        $this->assertWarningMatches($errors, '/^mysql_fetch_row\(\): \d+ is not a valid MySQL result resource$/');
    }

    public function testNumRowsAndFields(): void
    {
        $result = $this->people();
        $this->assertSame(3, mysql_num_rows($result));
        $this->assertSame(4, mysql_num_fields($result));
    }

    public function testDataSeek(): void
    {
        $result = $this->people();
        $this->assertTrue(mysql_data_seek($result, 2));
        $this->assertSame('Carol', mysql_fetch_assoc($result)['name']);
        $this->assertTrue(mysql_data_seek($result, 0));
        $this->assertSame('Alice', mysql_fetch_assoc($result)['name']);
    }

    public function testDataSeekOutOfRangeWarns(): void
    {
        $result = $this->people();
        [$ok, $errors] = $this->captureErrors(function () use ($result) {
            return mysql_data_seek($result, 3);
        });
        $this->assertFalse($ok);
        $this->assertWarning($errors, 'mysql_data_seek(): Offset 3 is invalid for MySQL result index');
    }

    public function testDataSeekOnEmptyResultWarns(): void
    {
        $result = mysql_query('SELECT 1 FROM compat_people WHERE 0 = 1');
        [$ok, $errors] = $this->captureErrors(function () use ($result) {
            return mysql_data_seek($result, 0);
        });
        $this->assertFalse($ok);
        $this->assertWarning($errors, 'Offset 0 is invalid');
    }

    public function testResultByOffsetAndName(): void
    {
        $result = $this->people();
        $this->assertSame('1', mysql_result($result, 0));
        $this->assertSame('Bob', mysql_result($result, 1, 'name'));
        $this->assertSame('Carol', mysql_result($result, 2, 1));
        $this->assertSame('Alice', mysql_result($result, 0, 'NAME'), 'Field names are case-insensitive');
        $this->assertSame('Bob', mysql_result($result, 1, 'compat_people.name'));
        $this->assertNull(mysql_result($result, 1, 'email'));
    }

    public function testResultWithAliasedColumnAndTable(): void
    {
        $result = mysql_query('SELECT p.name AS who FROM compat_people p ORDER BY p.id');
        $this->assertSame('Bob', mysql_result($result, 1, 'who'));
        $this->assertSame('Bob', mysql_result($result, 1, 'p.who'));
    }

    public function testResultLeavesCursorOnTheFollowingRow(): void
    {
        $result = $this->people();
        mysql_result($result, 1);
        $this->assertSame('Carol', mysql_fetch_assoc($result)['name']);
    }

    public function testResultWithBadFieldLeavesCursorOnTheRequestedRow(): void
    {
        $result = $this->people();
        @mysql_result($result, 1, 'nope');
        $this->assertSame('Bob', mysql_fetch_assoc($result)['name']);
        @mysql_result($result, 2, 9);
        $this->assertSame('Carol', mysql_fetch_assoc($result)['name']);
    }

    public function testResultRowOutOfRangeWarns(): void
    {
        $result = $this->people();
        [$value, $errors] = $this->captureErrors(function () use ($result) {
            return mysql_result($result, 5);
        });
        $this->assertFalse($value);
        $this->assertWarning($errors, 'mysql_result(): Unable to jump to row 5 on MySQL result index');
    }

    public function testResultUnknownFieldNameWarns(): void
    {
        $result = $this->people();
        [$value, $errors] = $this->captureErrors(function () use ($result) {
            return mysql_result($result, 0, 'nope');
        });
        $this->assertFalse($value);
        $this->assertWarning($errors, 'mysql_result(): nope not found in MySQL result index');

        [$value, $errors] = $this->captureErrors(function () use ($result) {
            return mysql_result($result, 0, 'other.name');
        });
        $this->assertFalse($value);
        $this->assertWarning($errors, 'mysql_result(): other.name not found in MySQL result index');
    }

    public function testResultBadFieldOffsetWarns(): void
    {
        $result = $this->people();
        [$value, $errors] = $this->captureErrors(function () use ($result) {
            return mysql_result($result, 0, 9);
        });
        $this->assertFalse($value);
        $this->assertWarning($errors, 'mysql_result(): Bad column offset specified');
    }

    public function testFreeResult(): void
    {
        $this->assertTrue(mysql_free_result($this->people()));
    }

    public function testFreeResultOnNonResultWarns(): void
    {
        [$value, $errors] = $this->captureErrors(function () {
            return mysql_free_result(true);
        });
        $this->assertNull($value);
        $this->assertWarning($errors, 'mysql_free_result() expects parameter 1 to be resource, boolean given');
    }

    public function testFreeResultTwiceWarns(): void
    {
        $result = $this->people();
        mysql_free_result($result);
        [$value, $errors] = $this->captureErrors(function () use ($result) {
            return mysql_free_result($result);
        });
        $this->assertFalse($value);
        $this->assertWarningMatches($errors, '/^mysql_free_result\(\): \d+ is not a valid MySQL result resource$/');
    }
}

class FetchTestPerson
{
    public $id;
    public $name;
    public $email;
    public $score;
    public $greeting;

    public function __construct($greeting = null)
    {
        $this->greeting = $greeting;
    }
}
