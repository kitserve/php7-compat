<?php

namespace Php7Compat\Tests\Misc;

use Php7Compat\Tests\TestCase;

/**
 * Functions and behaviour that need no database: constants, magic quotes
 * stubs, is_mysql_resource(), server-string parsing, safe re-inclusion.
 */
class MiscTest extends TestCase
{
    public function testConstantsMatchTheOriginalValues(): void
    {
        $this->assertSame(1, MYSQL_ASSOC);
        $this->assertSame(2, MYSQL_NUM);
        $this->assertSame(3, MYSQL_BOTH);
        $this->assertSame(32, MYSQL_CLIENT_COMPRESS);
        $this->assertSame(256, MYSQL_CLIENT_IGNORE_SPACE);
        $this->assertSame(1024, MYSQL_CLIENT_INTERACTIVE);
        $this->assertSame(2048, MYSQL_CLIENT_SSL);
    }

    public function testMagicQuotesStubs(): void
    {
        // On PHP 7.4 the native (deprecated) versions are used, hence the @.
        $this->assertFalse(@get_magic_quotes_gpc());
        $this->assertFalse(@get_magic_quotes_runtime());
        $this->assertFalse(set_magic_quotes_runtime(false), "PHP 5.4+ always returned false");
        $this->assertFalse(magic_quotes_runtime(false));
    }

    public function testEnablingMagicQuotesWarnsAndFails(): void
    {
        foreach (['set_magic_quotes_runtime', 'magic_quotes_runtime'] as $function) {
            [$result, $errors] = $this->captureErrors(function () use ($function) {
                return $function(true);
            });
            $this->assertFalse($result, $function);
            $this->assertWarning($errors, "$function(): magic_quotes_runtime is not supported anymore");
        }
    }

    public function testIsMysqlResource(): void
    {
        $this->assertFalse(is_mysql_resource(false));
        $this->assertFalse(is_mysql_resource(true));
        $this->assertFalse(is_mysql_resource(null));
        $this->assertFalse(is_mysql_resource(new \stdClass()));
        $this->assertTrue(is_mysql_resource(mysqli_init()));
        $handle = fopen('php://memory', 'r');
        $this->assertTrue(is_mysql_resource($handle));
        fclose($handle);
    }

    /**
     * @dataProvider serverStrings
     */
    public function testParseServer(string $server, array $expected): void
    {
        $this->assertSame($expected, \Php7Compat::parse_server($server));
    }

    public function serverStrings(): array
    {
        return [
            'host only' => ['db.example.com', ['db.example.com', null, null]],
            'host and port' => ['db.example.com:3307', ['db.example.com', 3307, null]],
            'host and socket' => ['localhost:/tmp/mysql.sock', ['localhost', null, '/tmp/mysql.sock']],
            'socket only' => [':/tmp/mysql.sock', ['localhost', null, '/tmp/mysql.sock']],
            'host, port and socket' => ['localhost:3307:/tmp/mysql.sock', ['localhost', 3307, '/tmp/mysql.sock']],
            'empty' => ['', ['localhost', null, null]],
            'ip and port' => ['127.0.0.1:33306', ['127.0.0.1', 33306, null]],
        ];
    }

    public function testLibraryCanBeIncludedRepeatedly(): void
    {
        require dirname(__DIR__, 2) . '/php7-compat.php';
        require dirname(__DIR__, 2) . '/php7-compat.php';
        $this->assertTrue(function_exists('mysql_query'));
    }

    public function testDefinesGenericMysqlAlias(): void
    {
        $this->assertTrue(function_exists('mysql'));
    }

    public function testDoesNotRequireMbstring(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/php7-compat.php');
        $this->assertStringNotContainsString('mb_', $source);
    }
}
