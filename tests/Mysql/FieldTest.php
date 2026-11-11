<?php

namespace Php7Compat\Tests\Mysql;

/**
 * Field metadata: mysql_fetch_field(), mysql_field_*().
 */
class FieldTest extends MysqlTestCase
{
    private function people()
    {
        return mysql_query('SELECT id, name, email, score, created, bio FROM compat_people ORDER BY id');
    }

    public function testFieldName(): void
    {
        $result = $this->people();
        $this->assertSame('id', mysql_field_name($result, 0));
        $this->assertSame('bio', mysql_field_name($result, 5));
    }

    public function testFieldInfoOutOfRangeWarns(): void
    {
        $result = $this->people();
        foreach (['mysql_field_name', 'mysql_field_table', 'mysql_field_len', 'mysql_field_type', 'mysql_field_flags'] as $function) {
            [$value, $errors] = $this->captureErrors(function () use ($function, $result) {
                return $function($result, 9);
            });
            $this->assertFalse($value, $function);
            $this->assertWarning($errors, "$function(): Field 9 is invalid for MySQL result index");
        }
    }

    public function testFieldTableUsesAlias(): void
    {
        $this->assertSame('compat_people', mysql_field_table($this->people(), 1));
        $this->assertSame('p', mysql_field_table(mysql_query('SELECT p.name FROM compat_people p'), 0));
    }

    public function testFieldLen(): void
    {
        $this->assertSame(10, mysql_field_len($this->people(), 0));
    }

    public function testFieldTypeUsesOriginalTypeNames(): void
    {
        $result = $this->people();
        $types = [];
        for ($i = 0; $i < mysql_num_fields($result); $i++) {
            $types[] = mysql_field_type($result, $i);
        }
        $this->assertSame(['int', 'string', 'string', 'real', 'datetime', 'blob'], $types);
    }

    public function testFieldTypeForOtherColumnTypes(): void
    {
        $result = mysql_query('SELECT * FROM compat_types');
        $types = [];
        for ($i = 0; $i < mysql_num_fields($result); $i++) {
            $types[mysql_field_name($result, $i)] = mysql_field_type($result, $i);
        }
        $this->assertSame([
            'd' => 'date',
            't' => 'time',
            'ts' => 'timestamp',
            'y' => 'year',
            'e' => 'string', // The original also reported ENUM and SET as "string"
            's' => 'string',
            'c' => 'string',
            'f' => 'real',
            'dbl' => 'real',
            'bi' => 'int',
            'ti' => 'int',
            'si' => 'int',
            'mi' => 'int',
            'bl' => 'blob',
        ], $types);
    }

    public function testFieldTypeOfNullLiteral(): void
    {
        $this->assertSame('null', mysql_field_type(mysql_query('SELECT NULL'), 0));
    }

    public function testFieldFlags(): void
    {
        $result = $this->people();
        $this->assertSame('not_null primary_key unsigned auto_increment', mysql_field_flags($result, 0));
        $this->assertSame('not_null multiple_key', mysql_field_flags($result, 1));
        $this->assertSame('unique_key', mysql_field_flags($result, 2));
        $this->assertSame('', mysql_field_flags($result, 3));
        $this->assertSame('blob', mysql_field_flags($result, 5));

        $types = mysql_query('SELECT e, s, bl FROM compat_types');
        $this->assertSame('enum', mysql_field_flags($types, 0));
        $this->assertSame('set', mysql_field_flags($types, 1));
        $this->assertSame('blob binary', mysql_field_flags($types, 2));
    }

    public function testFetchFieldReturnsOriginalObjectShape(): void
    {
        $field = mysql_fetch_field($this->people());
        $this->assertSame([
            'name' => 'id',
            'table' => 'compat_people',
            'def' => '',
            'max_length' => 1,
            'not_null' => 1,
            'primary_key' => 1,
            'multiple_key' => 0,
            'unique_key' => 0,
            'numeric' => 1,
            'blob' => 0,
            'type' => 'int',
            'unsigned' => 1,
            'zerofill' => 0,
        ], get_object_vars($field));
    }

    public function testFetchFieldMaxLengths(): void
    {
        $result = $this->people();
        $max = [];
        while (($field = mysql_fetch_field($result)) !== false) {
            $max[$field->name] = $field->max_length;
        }
        $this->assertSame(['id' => 1, 'name' => 5, 'email' => 17, 'score' => 4, 'created' => 19, 'bio' => 28], $max);
    }

    public function testFetchFieldFlagsForOtherColumns(): void
    {
        $result = $this->people();
        $name = mysql_fetch_field($result, 1);
        $this->assertSame([1, 1, 0, 0, 0, 'string'], [$name->not_null, $name->multiple_key, $name->unique_key, $name->numeric, $name->blob, $name->type]);
        $email = mysql_fetch_field($result, 2);
        $this->assertSame([0, 1], [$email->not_null, $email->unique_key]);
        $bio = mysql_fetch_field($result, 5);
        $this->assertSame([1, 'blob', 0], [$bio->blob, $bio->type, $bio->numeric]);
        $score = mysql_fetch_field($result, 3);
        $this->assertSame([1, 'real'], [$score->numeric, $score->type]);
    }

    public function testTimestampIsNumericAndBitIsInt(): void
    {
        $this->assertTrue(mysql_query('CREATE TEMPORARY TABLE compat_bits (b BIT(4))'));
        $this->assertSame([1, 'timestamp'], (function ($f) {
            return [$f->numeric, $f->type];
        })(mysql_fetch_field(mysql_query('SELECT ts FROM compat_types'))));
        $this->assertSame('int', mysql_field_type(mysql_query('SELECT b FROM compat_bits'), 0));
    }

    public function testFetchFieldDoesNotDisturbFetchLengths(): void
    {
        $result = $this->people();
        $this->assertFalse(mysql_fetch_lengths($result));
        mysql_fetch_field($result);
        $this->assertFalse(mysql_fetch_lengths($result), 'Still no current row');

        mysql_fetch_row($result);
        mysql_fetch_field($result, 5);
        $this->assertSame([1, 5, 17, 4, 19, 10], mysql_fetch_lengths($result), "Still Alice's row");

        mysql_fetch_row($result);
        $this->assertSame([1, 3, 0, 4, 0, 0], mysql_fetch_lengths($result), "Bob's row");
    }

    public function testFetchFieldDoesNotDisturbTheRowCursor(): void
    {
        $result = $this->people();
        $this->assertSame('Alice', mysql_fetch_assoc($result)['name']);
        $this->assertSame(28, mysql_fetch_field($result, 5)->max_length);
        $this->assertSame('Bob', mysql_fetch_assoc($result)['name']);

        mysql_data_seek($result, 2);
        mysql_fetch_field($result, 0);
        $this->assertSame('Carol', mysql_fetch_assoc($result)['name']);
        $this->assertFalse(mysql_fetch_assoc($result));
        mysql_fetch_field($result, 0);
        $this->assertFalse(mysql_fetch_assoc($result), 'Cursor should stay at the end');

        mysql_result($result, 0);
        mysql_fetch_field($result, 0);
        $this->assertSame('Bob', mysql_fetch_assoc($result)['name']);
    }

    public function testFetchFieldMaxLengthIsZeroForUnbufferedResults(): void
    {
        $result = mysql_unbuffered_query('SELECT name FROM compat_people');
        $this->assertSame(0, mysql_fetch_field($result)->max_length);
        mysql_free_result($result);
    }

    public function testFetchFieldOffsetIsAbsoluteAndMovesTheFieldCursor(): void
    {
        $result = $this->people();
        $this->assertSame('id', mysql_fetch_field($result)->name);
        $this->assertSame('name', mysql_fetch_field($result)->name);
        $this->assertSame('created', mysql_fetch_field($result, 4)->name);
        $this->assertSame('bio', mysql_fetch_field($result)->name);
        $this->assertFalse(mysql_fetch_field($result));
        $this->assertSame('email', mysql_fetch_field($result, 2)->name);
        $this->assertSame('score', mysql_fetch_field($result)->name);
    }

    /** The original warned about a bad offset but still returned the next field. */
    public function testFetchFieldBadOffsetWarnsButReturnsNextField(): void
    {
        $result = $this->people();
        [$field, $errors] = $this->captureErrors(function () use ($result) {
            return mysql_fetch_field($result, 99);
        });
        $this->assertWarning($errors, 'mysql_fetch_field(): Bad field offset');
        $this->assertSame('id', $field->name);
    }

    public function testFieldInfoFunctionsMoveTheFieldCursor(): void
    {
        $result = $this->people();
        mysql_field_name($result, 2);
        $this->assertSame('score', mysql_fetch_field($result)->name);
    }

    public function testFieldSeek(): void
    {
        $result = $this->people();
        $this->assertTrue(mysql_field_seek($result, 3));
        $this->assertSame('score', mysql_fetch_field($result)->name);

        [$ok, $errors] = $this->captureErrors(function () use ($result) {
            return mysql_field_seek($result, 6);
        });
        $this->assertFalse($ok);
        $this->assertWarning($errors, 'mysql_field_seek(): Field 6 is invalid for MySQL result index');
    }
}
