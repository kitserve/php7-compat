<?php
/**
 * Backwards compatibility library for obsolete PHP functions
 *
 * The mysql_* functions and ereg* functions were removed in PHP 7.
 * Many legacy websites contain too much legacy code to fully rewrite
 * so a compatibility library is required. It should be included in the
 * auto_prepend_file setting in php.ini for the site. This is the only
 * way of guaranteeing it gets loaded in every file on the site without
 * analysing each file individually. Note that not all mysql_*
 * functions have been overridden. If more are required to maintain
 * compatibility then they can be added here. Contributions welcome via
 * GitHub.
 *
 * @author     Kitson Consulting <github at kitson minus consulting dot co dot uk>
 * @copyright  2019-2020 Kitson Consulting Limited
 * @license    https://www.apache.org/licenses/LICENSE-2.0
 * @package    php7-compat
 * @see        https://github.com/kitserve/php7-compat
 */

// Global variable to cache database link (this is used because many
// mysqli_* functions require it, but old mysql_* functions don't)
$_php7_compat_global_db_link = null;

// definitions required for old mysql_connect $client_flags
if( !defined( 'MYSQL_CLIENT_COMPRESS' ) ) define( 'MYSQL_CLIENT_COMPRESS', MYSQLI_CLIENT_COMPRESS );
if( !defined( 'MYSQL_CLIENT_IGNORE_SPACE' ) ) define( 'MYSQL_CLIENT_IGNORE_SPACE', MYSQLI_CLIENT_IGNORE_SPACE );
if( !defined( 'MYSQL_CLIENT_INTERACTIVE' ) ) define( 'MYSQL_CLIENT_INTERACTIVE', MYSQLI_CLIENT_INTERACTIVE );
if( !defined( 'MYSQL_CLIENT_SSL' ) ) define( 'MYSQL_CLIENT_SSL', MYSQLI_CLIENT_SSL );

// definitions required for old mysql_fetch_array $result_type
if( !defined( 'MYSQL_ASSOC' ) ) define( 'MYSQL_ASSOC', MYSQLI_ASSOC );
if( !defined( 'MYSQL_BOTH' ) ) define( 'MYSQL_BOTH', MYSQLI_BOTH );
if( !defined( 'MYSQL_NUM' ) ) define( 'MYSQL_NUM', MYSQLI_NUM );
        
if( !function_exists( 'mysql_affected_rows' ) )
{
	function mysql_affected_rows( $link = null )
	{
		if( !$link )
		{
			global $_php7_compat_global_db_link;
			$link = $_php7_compat_global_db_link;
		}
		return mysqli_affected_rows( $link );
	}
}

if( !function_exists( 'mysql_close' ) )
{
	function mysql_close( $link = null )
	{
		if( !$link )
		{
			global $_php7_compat_global_db_link;
			$link = $_php7_compat_global_db_link;
		}
		return mysqli_close( $link );
	}
}

if( !function_exists( 'mysql_connect' ) )
{
	function mysql_connect( $server = '', $user = '', $password = '', $new_link = false, $client_flags = 0 )
	{
		if( !$server ) $server = ini_get( 'mysqli.default_host' );
		if( !$user ) $user = ini_get( 'mysqli.default_user' );
		if( !$password ) $password = ini_get( 'mysqli.default_pw' );
		return mysqli_connect( $server, $user, $password );
	}
}

if( !function_exists( 'mysql_data_seek' ) )
{
	function mysql_data_seek( $result, $offset )
	{
		return mysqli_data_seek( $result, $offset );
	}
}

if( !function_exists( 'mysql_db_query' ) )
{
	function mysql_db_query( $dbname, $query, $link )
	{
		if( !$link )
		{
			global $_php7_compat_global_db_link;
			$link = $_php7_compat_global_db_link;
		}
		mysqli_select_db( $link, $dbname );
		$result = mysqli_query( $link, $query );
		return $result;
	}
}

if( !function_exists( 'mysql_errno' ) )
{
	function mysql_errno( $link = null )
	{
		if( !$link )
		{
			global $_php7_compat_global_db_link;
			$link = $_php7_compat_global_db_link;
		}
		return mysqli_errno( $link );
	}
}

if( !function_exists( 'mysql_error' ) )
{
	function mysql_error( $link = null )
	{
		if( !$link )
		{
			global $_php7_compat_global_db_link;
			$link = $_php7_compat_global_db_link;
		}
		return mysqli_error( $link );
	}
}

if( !function_exists( 'mysql_escape_string' ) )
{
	function mysql_escape_string( $unescaped_string, $link = null )
	{
		if( !$link )
		{
			global $_php7_compat_global_db_link;
			$link = $_php7_compat_global_db_link;
		}
		return mysqli_real_escape_string( $link, $unescaped_string );
	}
}

if( !function_exists( 'mysql_fetch_array' ) )
{
	function mysql_fetch_array( $result, $result_type = MYSQLI_BOTH )
	{
		return mysqli_fetch_array( $result, $result_type );
	}
}

if( !function_exists( 'mysql_fetch_assoc' ) )
{
	function mysql_fetch_assoc( $result )
	{
		return mysqli_fetch_assoc( $result );
	}
}

if( !function_exists( 'mysql_fetch_field' ) )
{
	function mysql_fetch_field( $result, $field_offset = 0 )
	{
		for( $i = 0; $i < $field_offset; ++$i )
		{
			mysqli_fetch_field( $result );
		}
		return mysqli_fetch_field( $result );
	}
}

if( !function_exists( 'mysql_fetch_object' ) )
{
	function mysql_fetch_object( $result, $class_name = 'stdClass', $params = null )
	{
		if( is_array( $params ) ) return mysqli_fetch_object( $result, $class_name, $params );
		else return mysqli_fetch_object( $result, $class_name );
	}
}

if( !function_exists( 'mysql_fetch_row' ) )
{
	function mysql_fetch_row( $result )
	{
		return mysqli_fetch_row( $result );
	}
}

if( !function_exists( 'mysql_field_name' ) )
{
	function mysql_field_name( $result, $field_offset )
	{
		return mysqli_fetch_field_direct( $result, $field_offset )->name;
	}
}

if( !function_exists( 'mysql_field_type' ) )
{
	function mysql_field_type( $result, $field_offset )
	{
		$type_id = mysqli_fetch_field_direct( $result, $field_offset)->type;
		$types = array();
		$constants = get_defined_constants( true );
		foreach( $constants['mysqli'] as $c => $n )
		{
			if( preg_match( '/^MYSQLI_TYPE_(.*)/', $c, $m ) )
			{
				$types[$n] = $m[1];
			}
		}
		return array_key_exists( $type_id, $types ) ? $types[$type_id] : null;
	}
}

if( !function_exists( 'mysql_free_result' ) )
{
	function mysql_free_result( $result )
	{
		return mysqli_free_result( $result );
	}
}

if( !function_exists( 'mysql_get_server_info' ) )
{
	function mysql_get_server_info( $link = null )
	{
		if( !$link )
		{
			global $_php7_compat_global_db_link;
			$link = $_php7_compat_global_db_link;
		}
		return mysqli_get_server_info( $link );
	}
}

if( !function_exists( 'mysql_insert_id' ) )
{
	function mysql_insert_id( $link = null )
	{
		if( !$link )
		{
			global $_php7_compat_global_db_link;
			$link = $_php7_compat_global_db_link;
		}
		return mysqli_insert_id( $link );
	}
}

if( !function_exists( 'mysql_list_dbs' ) )
{
	function mysql_list_dbs( $link = null )
	{
		if( !$link )
		{
			global $_php7_compat_global_db_link;
			$link = $_php7_compat_global_db_link;
		}
		return mysqli_query( $link, 'SHOW DATABASES' );
	}
}

if( !function_exists( 'mysql_list_fields' ) )
{
	function mysql_list_fields( $dbname, $table_name, $link = null )
	{
		if( !$link )
		{
			global $_php7_compat_global_db_link;
			$link = $_php7_compat_global_db_link;
		}
		mysqli_select_db( $link, $dbname );
		return mysqli_query( $link, "SHOW COLUMNS FROM $table_name" );
	}
}

if( !function_exists( 'mysql_num_fields' ) )
{
	function mysql_num_fields( $result )
	{
		return mysqli_num_fields( $result );
	}
}

if( !function_exists( 'mysql_num_rows' ) )
{
	function mysql_num_rows( $result )
	{
		return mysqli_num_rows( $result );
	}
}

if( !function_exists( 'mysql_numrows' ) )
{
	function mysql_numrows( $result )
	{
		return mysqli_num_rows( $result );
	}
}

if( !function_exists( 'mysql_pconnect' ) )
{
	function mysql_pconnect( $server = '', $user = '', $password = '', $client_flags = 0 )
	{
		global $_php7_compat_global_db_link;

		if( !$server ) $server = ini_get( 'mysqli.default_host' );
		if( !$user ) $user = ini_get( 'mysqli.default_user' );
		if( !$password ) $password = ini_get( 'mysqli.default_pw' );

		$link = mysqli_connect( $server, $user, $password );
		if( !$_php7_compat_global_db_link ) $_php7_compat_global_db_link = $link;
		return $link;
	}
}

if( !function_exists( 'mysql_ping' ) )
{
	function mysql_ping( $link = null )
	{
		if( !$link )
		{
			global $_php7_compat_global_db_link;
			$link = $_php7_compat_global_db_link;
		}
		return mysqli_ping( $link );
	}
}

if( !function_exists( 'mysql_query' ) )
{
	function mysql_query( $query, $link = null )
	{
		if( !$link )
		{
			global $_php7_compat_global_db_link;
			$link = $_php7_compat_global_db_link;
		}
		return mysqli_query( $link, $query );
	}
}

if( !function_exists( 'mysql_real_escape_string' ) )
{
	function mysql_real_escape_string( $unescaped_string, $link = null )
	{
		if( !$link )
		{
			global $_php7_compat_global_db_link;
			$link = $_php7_compat_global_db_link;
		}
		return mysqli_real_escape_string( $link, $unescaped_string );
	}
}

if( !function_exists( 'mysql_result' ) )
{
	function mysql_result( $result, $row, $field = 0 )
	{
		mysqli_data_seek( $result, $row );
		$row = mysqli_fetch_array( $result, MYSQLI_BOTH );
		return $row[$field];
	}
}

if( !function_exists( 'mysql_select_db' ) )
{
	function mysql_select_db( $database, $link = null )
	{
		if( !$link )
		{
			global $_php7_compat_global_db_link;
			$link = $_php7_compat_global_db_link;
		}
		return mysqli_select_db( $link, $database );
	}
}

if( !function_exists( 'mysql_set_charset' ) )
{
	function mysql_set_charset( $database, $link = null )
	{
		if( !$link )
		{
			global $_php7_compat_global_db_link;
			$link = $_php7_compat_global_db_link;
		}
		return mysqli_set_charset( $link, $database );
	}
}

if( !function_exists( 'ereg_replace' ) )
{
	function ereg_replace( $pattern, $replacement, $string )
	{
		return preg_replace( '/' . preg_quote( $pattern, '/' ) . '/', $replacement, $string );
	}
}

if( !function_exists( 'eregi_replace' ) )
{
	function eregi_replace( $pattern, $replacement, $string )
	{
		return preg_replace( '/' . preg_quote( $pattern, '/' ) . '/i', $replacement, $string );
	}
}

if( !function_exists( 'ereg' ) )
{
	function ereg( $pattern, $string, $regs = null )
	{
		if( $regs ) return preg_match( '/' . preg_quote( $pattern, '/' ) . '/', $string, $regs );
		else return preg_match( '/' . preg_quote( $pattern, '/' ) . '/', $string );
	}
}

if( !function_exists( 'eregi' ) )
{
	function eregi( $pattern, $string, $regs = null )
	{
		if( $regs ) return preg_match( '/' . preg_quote( $pattern, '/' ) . '/i', $string, $regs );
		else return preg_match( '/' . preg_quote( $pattern, '/' ) . '/i', $string );
	}
}

if( !function_exists( 'split' ) )
{
	function split( $pattern, $string, $limit = -1 )
	{
		return preg_split( '/' . preg_quote( $pattern, '/' ) . '/', $string, $limit );
	}
}

if( !function_exists( 'spliti' ) )
{
	function spliti( $pattern, $string, $limit = -1 )
	{
		return preg_split( '/' . preg_quote( $pattern, '/' ) . '/i', $string, $limit );
	}
}

if( !function_exists( 'set_magic_quotes_runtime' ) )
{
	function set_magic_quotes_runtime( $new_setting )
	{
		if( $new_setting ) return false;
		else return true;
	}
}

if( !function_exists( 'random_int' ) )
{
	define( 'OPENSSL_RAND_MAX', mt_getrandmax() );
	function random_int( $min = 0, $max = OPENSSL_RAND_MAX )
	{
		$range = $max - $min;
		if( $range == 0 ) return $min;
		$log = log( $range, 2 );
		$bytes = (int) ( $log / 8 ) + 1;
		$bits = (int) $log + 1;
		$filter = (int) ( 1 << $bits ) - 1;
		do
		{
			$rnd = hexdec( bin2hex( openssl_random_pseudo_bytes( $bytes, $s ) ) );
			$rnd = $rnd & $filter; // discard irrelevant bits
		} while( $rnd >= $range );
		return $min + $rnd;
	}
}

/**
 * Check if a MySQL resource was successfully returned
 *
 * mysql_* functions generally return resources. mysqli_* functions generally return objects.
 * Therefore if we wan to check whether a mysql_* function successfully returned, we can't
 * use is_resource() anymore once the mysql_* wrappers are in place. Instead we should check
 * with this function. It detects whether we're still running in old mysql_* mode, or if the
 * functions have been replaced with wrappers around their mysqli_* counterparts.
 *
 * @param   $resource  the resource to check
 * @return  boolean    true if resource/object, false otherwise
 */
if( !function_exists( 'is_mysql_resource' ) )
{
	function is_mysql_resource( $resource )
	{
		return is_resource( $resource ) or is_object( $resource );
	}
}

///:~
