<?php

namespace Php7Compat\Tests\Mysql;

/**
 * mysql_list_*(), mysql_db_name(), mysql_tablename(), and the deprecated
 * function-name aliases.
 */
class ListTest extends MysqlTestCase
{
    public function testListDbs(): void
    {
        $result = mysql_list_dbs();
        $this->assertSame('Database', mysql_field_name($result, 0));
        $names = [];
        for ($i = 0; $i < mysql_num_rows($result); $i++) {
            $names[] = mysql_db_name($result, $i);
        }
        $this->assertContains(self::dbName(), $names);
    }

    public function testListTables(): void
    {
        $result = mysql_list_tables(self::dbName());
        $names = [];
        for ($i = 0; $i < mysql_num_rows($result); $i++) {
            $names[] = mysql_tablename($result, $i);
        }
        $this->assertContains('compat_people', $names);
        $this->assertContains('compat_types', $names);
    }

    /** The original selected the database as a side effect, so we do too. */
    public function testListTablesSelectsTheDatabase(): void
    {
        mysql_select_db('information_schema');
        mysql_list_tables(self::dbName());
        $this->assertSame(self::dbName(), mysql_result(mysql_query('SELECT DATABASE()'), 0));
    }

    public function testListFieldsReturnsFieldMetadataNotRows(): void
    {
        $result = mysql_list_fields(self::dbName(), 'compat_people');
        $this->assertSame(0, mysql_num_rows($result));
        $this->assertSame(6, mysql_num_fields($result));
        $this->assertSame('id', mysql_field_name($result, 0));
        $this->assertSame('bio', mysql_field_name($result, 5));
        $this->assertSame('not_null primary_key unsigned auto_increment', mysql_field_flags($result, 0));
        $this->assertSame('compat_people', mysql_field_table($result, 0));
    }

    /**
     * The expected values come from running the original extension (PHP 5.6)
     * against the same table.
     */
    public function testListFieldsReportsColumnDefaults(): void
    {
        $this->assertTrue(mysql_query("CREATE TABLE compat_defaults (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            i_nn INT NOT NULL,
            i_null INT NULL,
            i_def INT NOT NULL DEFAULT 42,
            i_neg INT DEFAULT -7,
            d_def DECIMAL(5,2) DEFAULT 1.5,
            d_nn DECIMAL(5,2) NOT NULL,
            f_def FLOAT DEFAULT 2.5,
            v_nn VARCHAR(10) NOT NULL,
            v_def VARCHAR(10) DEFAULT 'abc',
            v_quote VARCHAR(10) DEFAULT 'it''s',
            v_nulltext VARCHAR(10) DEFAULT 'NULL',
            t_text TEXT,
            e_nn ENUM('a','b') NOT NULL,
            e_def ENUM('a','b') DEFAULT 'b',
            s_def SET('x','y') DEFAULT 'x,y',
            dt_nn DATETIME NOT NULL,
            dt_def DATETIME DEFAULT '2020-01-02 03:04:05',
            dt_cur DATETIME DEFAULT CURRENT_TIMESTAMP,
            ts_cur TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            ts_null TIMESTAMP NULL,
            da_def DATE DEFAULT '2020-01-02',
            tm_def TIME DEFAULT '01:02:03',
            y_def YEAR DEFAULT 2001,
            b_def BIT(4) DEFAULT b'101'
        )"), mysql_error());
        try {
            $result = mysql_list_fields(self::dbName(), 'compat_defaults');
            $defaults = [];
            while (($field = mysql_fetch_field($result)) !== false) {
                $defaults[$field->name] = $field->def;
            }
            $this->assertSame([
                'id' => '0', 'i_nn' => '0', 'i_null' => '', 'i_def' => '42', 'i_neg' => '-7',
                'd_def' => '1.50', 'd_nn' => '0.00', 'f_def' => '2.5', 'v_nn' => '', 'v_def' => 'abc',
                'v_quote' => "it's", 'v_nulltext' => 'NULL', 't_text' => '', 'e_nn' => 'a', 'e_def' => 'b',
                's_def' => 'x,y', 'dt_nn' => '0000-00-00 00:00:00', 'dt_def' => '2020-01-02 03:04:05',
                'dt_cur' => '', 'ts_cur' => '0000-00-00 00:00:00', 'ts_null' => '', 'da_def' => '2020-01-02',
                'tm_def' => '01:02:03', 'y_def' => '2001', 'b_def' => "\x05",
            ], $defaults);

            // Ordinary query results have no defaults
            $this->assertSame('', mysql_fetch_field(mysql_query('SELECT * FROM compat_defaults'), 3)->def);
        } finally {
            mysql_query('DROP TABLE compat_defaults');
        }
    }

    public function testListFieldsSelectsTheDatabase(): void
    {
        mysql_select_db('information_schema');
        mysql_list_fields(self::dbName(), 'compat_people', $this->link);
        $this->assertSame(self::dbName(), mysql_result(mysql_query('SELECT DATABASE()'), 0));
    }

    public function testListFieldsQuotesTheTableName(): void
    {
        $this->assertTrue(mysql_query('CREATE TABLE `compat odd``name` (x INT)'));
        try {
            $result = mysql_list_fields(self::dbName(), 'compat odd`name');
            $this->assertSame('x', mysql_field_name($result, 0));
        } finally {
            mysql_query('DROP TABLE `compat odd``name`');
        }
    }

    public function testListFieldsForMissingTableWarns(): void
    {
        [$result, $errors] = $this->captureErrors(function () {
            return mysql_list_fields(self::dbName(), 'compat_no_such_table');
        });
        $this->assertFalse($result);
        $this->assertWarning($errors, 'mysql_list_fields(): Unable to save MySQL query result');
        $this->assertSame(1146, mysql_errno());
    }

    public function testListProcesses(): void
    {
        $result = mysql_list_processes();
        $this->assertGreaterThanOrEqual(1, mysql_num_rows($result));
        $this->assertSame('Id', mysql_field_name($result, 0));
    }

    public function testAliases(): void
    {
        $result = mysql_query('SELECT id, name FROM compat_people ORDER BY id');
        $this->assertSame('id', mysql_fieldname($result, 0));
        $this->assertSame('compat_people', mysql_fieldtable($result, 0));
        $this->assertSame(10, mysql_fieldlen($result, 0));
        $this->assertSame('int', mysql_fieldtype($result, 0));
        $this->assertSame('not_null multiple_key', mysql_fieldflags($result, 1));
        $this->assertSame(2, mysql_numfields($result));
        $this->assertSame(3, mysql_numrows($result));
        $this->assertSame('Bob', mysql_table_name($result, 1, 'name'));
        $this->assertSame('Carol', mysql_dbname($result, 2, 1));
        $this->assertTrue(mysql_freeresult($result));

        $this->assertTrue(mysql_selectdb(self::dbName()));
        $this->assertSame('Database', mysql_field_name(mysql_listdbs(), 0));
        $this->assertGreaterThanOrEqual(2, mysql_num_rows(mysql_listtables(self::dbName())));
        $this->assertSame(6, mysql_num_fields(mysql_listfields(self::dbName(), 'compat_people')));
        $this->assertSame('3', mysql_result(mysql(self::dbName(), 'SELECT COUNT(*) FROM compat_people'), 0));
        $this->assertSame('1', mysql_result(mysql(self::dbName(), 'SELECT 1', $this->link), 0));
        $this->assertTrue(function_exists('mysql_createdb'));
        $this->assertTrue(function_exists('mysql_dropdb'));
    }

    public function testAliasesPassThroughArgumentCount(): void
    {
        // mysql_selectdb() with an explicit null link must fail like mysql_select_db() does.
        [$result, $errors] = $this->captureErrors(function () {
            return mysql_selectdb(self::dbName(), null);
        });
        $this->assertNull($result);
        $this->assertWarning($errors, 'expects parameter 2 to be resource, null given');
    }
}
