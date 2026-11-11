<?php
/**
 * Backwards compatibility library for obsolete PHP functions
 *
 * The mysql_* and ereg* functions were removed in PHP 7. Many legacy
 * websites contain too much legacy code to fully rewrite, so a
 * compatibility library is required. It should be included in the
 * auto_prepend_file setting in php.ini for the site. This is the only
 * way of guaranteeing it gets loaded in every file on the site without
 * analysing each file individually.
 *
 * The wrappers aim to reproduce the behaviour of the original PHP 5
 * functions as closely as possible, including return values, warnings
 * and edge cases. Known differences are listed in README.md.
 *
 * Requires PHP 7.0 or later and the mysqli extension.
 *
 * @author     Kitson Consulting <github at kitson minus consulting dot co dot uk>
 * @copyright  2019-2026 Kitson Consulting Limited
 * @license    https://www.apache.org/licenses/LICENSE-2.0
 * @package    php7-compat
 * @see        https://github.com/kitserve/php7-compat
 */

// Global variable holding the default database link: the most recently
// opened connection, used whenever a mysql_* function is called without a
// link. Guarded so that including this file again doesn't lose the link.
if( !isset( $GLOBALS['_php7_compat_global_db_link'] ) )
{
	$GLOBALS['_php7_compat_global_db_link'] = null;
}

// definitions required for old mysql_connect $client_flags
if( !defined( 'MYSQL_CLIENT_COMPRESS' ) ) define( 'MYSQL_CLIENT_COMPRESS', MYSQLI_CLIENT_COMPRESS );
if( !defined( 'MYSQL_CLIENT_IGNORE_SPACE' ) ) define( 'MYSQL_CLIENT_IGNORE_SPACE', MYSQLI_CLIENT_IGNORE_SPACE );
if( !defined( 'MYSQL_CLIENT_INTERACTIVE' ) ) define( 'MYSQL_CLIENT_INTERACTIVE', MYSQLI_CLIENT_INTERACTIVE );
if( !defined( 'MYSQL_CLIENT_SSL' ) ) define( 'MYSQL_CLIENT_SSL', MYSQLI_CLIENT_SSL );

// definitions required for old mysql_fetch_array $result_type
if( !defined( 'MYSQL_ASSOC' ) ) define( 'MYSQL_ASSOC', MYSQLI_ASSOC );
if( !defined( 'MYSQL_BOTH' ) ) define( 'MYSQL_BOTH', MYSQLI_BOTH );
if( !defined( 'MYSQL_NUM' ) ) define( 'MYSQL_NUM', MYSQLI_NUM );

if( !class_exists( 'Php7Compat', false ) )
{
	/**
	 * Shared state and helpers used by the wrapper functions.
	 *
	 * @internal Not part of the public API; may change without notice.
	 */
	final class Php7Compat
	{
		/** @var array<string,mysqli> non-persistent links by connection details, so mysql_connect() can reuse them */
		public static $links = array();

		/** @var string|null message from the most recent failed connection attempt */
		public static $connect_error = null;

		/** @var int error number from the most recent failed connection attempt */
		public static $connect_errno = 0;

		/** @var WeakMap|SplObjectStorage|null link => unbuffered result that may still have unread rows */
		public static $pending = null;

		/** @var WeakMap|SplObjectStorage|null result => row cursor position (see track_cursor()) */
		public static $cursors = null;

		/** @var WeakMap|SplObjectStorage|null mysql_list_fields() result => column default values */
		public static $list_defaults = null;

		/**
		 * @var WeakMap|null result => what mysql_fetch_lengths() should return,
		 *      saved before a max_length scan disturbed it (PHP 8.1+ only)
		 */
		public static $saved_lengths = null;

		/** @var WeakMap|null result => array of per-column max lengths (PHP 8.1+ only) */
		public static $max_lengths = null;

		/** @var array<string,array> compiled ereg patterns, or error codes */
		public static $regex_cache = array();

		/**
		 * @var bool|null whether to raise deprecation notices; null means yes,
		 *      unless the PHP7COMPAT_NO_DEPRECATIONS constant is defined and
		 *      true. Intended for tests.
		 */
		public static $deprecations = null;

		/** @var string|null the alias being called, while it calls its target function */
		private static $alias = null;

		/** @var array<string,array> parsed parameter specifications */
		private static $specs = array();

		/**
		 * Forget all connections and cached state. Intended for tests.
		 */
		public static function reset()
		{
			self::$links = array();
			self::$connect_error = null;
			self::$connect_errno = 0;
			self::$pending = null;
			self::$cursors = null;
			self::$max_lengths = null;
			self::$list_defaults = null;
			self::$saved_lengths = null;
			$GLOBALS['_php7_compat_global_db_link'] = null;
		}

		// ------------------------------------------------------------------
		// General helpers
		// ------------------------------------------------------------------

		/**
		 * Emit a warning in the same format as the original functions. The
		 * originals raised E_WARNING, which userland code cannot do, so
		 * E_USER_WARNING is the closest equivalent.
		 */
		public static function warn( $function, $message )
		{
			trigger_error( self::name( $function ) . '(): ' . $message, E_USER_WARNING );
		}

		/**
		 * The function name to use in messages: the alias, if the function
		 * was called through one, as PHP reported the name that was called.
		 */
		public static function name( $function )
		{
			return self::$alias !== null ? self::$alias : $function;
		}

		/** The type names PHP 5 used in parameter messages. */
		private static function type_of( $value )
		{
			$type = strtolower( gettype( $value ) );
			return strpos( $type, 'resource' ) === 0 ? 'resource' : $type;
		}

		/**
		 * Check a function's arguments the way PHP 5 checked a built-in
		 * function's parameters, using the same specification strings as the
		 * PHP 5.6 source: s = string, l = integer, b = boolean, r = resource
		 * (here, a mysqli link or result), z/Z = anything, ! after a type =
		 * null is allowed, | = the remaining parameters are optional.
		 *
		 * On failure, warns as PHP 5 did and returns false; the caller then
		 * returns null, as the original function did.
		 */
		public static function parse( $function, $args, $spec )
		{
			if( !isset( self::$specs[$spec] ) )
			{
				$params = array();
				$required = 0;
				$optional = false;
				$length = strlen( $spec );
				for( $i = 0; $i < $length; $i++ )
				{
					if( $spec[$i] === '|' ) $optional = true;
					elseif( $spec[$i] === '!' ) $params[count( $params ) - 1][1] = true;
					else
					{
						$params[] = array( $spec[$i], false );
						if( !$optional ) $required++;
					}
				}
				self::$specs[$spec] = array( $params, $required );
			}
			list( $params, $required ) = self::$specs[$spec];

			$count = count( $args );
			$maximum = count( $params );
			if( $count < $required || $count > $maximum )
			{
				$expected = $count < $required ? $required : $maximum;
				trigger_error( sprintf( '%s() expects %s %d parameter%s, %d given',
					self::name( $function ),
					$required === $maximum ? 'exactly' : ( $count < $required ? 'at least' : 'at most' ),
					$expected, $expected === 1 ? '' : 's', $count ), E_USER_WARNING );
				return false;
			}

			foreach( array_values( $args ) as $i => $arg )
			{
				list( $type, $nullable ) = $params[$i];
				if( $nullable && $arg === null ) continue;
				$expected = self::check_type( $type, $arg );
				if( $expected !== null )
				{
					trigger_error( self::name( $function ) . '() expects parameter ' . ( $i + 1 ) . ' to be ' . $expected . ', ' . self::type_of( $arg ) . ' given', E_USER_WARNING );
					return false;
				}
			}
			return true;
		}

		/** @return string|null the expected type name if $arg is unacceptable */
		private static function check_type( $type, $arg )
		{
			switch( $type )
			{
				case 's':
					if( is_scalar( $arg ) || $arg === null ) return null;
					if( is_object( $arg ) && method_exists( $arg, '__toString' ) ) return null;
					return 'string';
				case 'l':
					if( is_int( $arg ) || is_float( $arg ) || is_bool( $arg ) || $arg === null ) return null;
					if( is_string( $arg ) )
					{
						if( preg_match( '/^[ \t\n\r\v\f]*[+-]?([0-9]+(\.[0-9]*)?|\.[0-9]+)([eE][+-]?[0-9]+)?$/', $arg ) ) return null;
						if( preg_match( '/^[ \t\n\r\v\f]*[+-]?([0-9]+(\.[0-9]*)?|\.[0-9]+)/', $arg ) )
						{
							trigger_error( 'A non well formed numeric value encountered', E_USER_NOTICE );
							return null;
						}
					}
					return 'long';
				case 'b':
					return ( is_scalar( $arg ) || $arg === null ) ? null : 'boolean';
				case 'r':
					return ( is_resource( $arg ) || $arg instanceof mysqli || $arg instanceof mysqli_result ) ? null : 'resource';
				default:
					return null;
			}
		}

		/**
		 * Raise a deprecation notice, unless they've been switched off by
		 * defining PHP7COMPAT_NO_DEPRECATIONS. The constant is checked on each
		 * call, so it can be defined after this file has been loaded.
		 */
		public static function deprecated( $message )
		{
			$enabled = self::$deprecations !== null ? self::$deprecations : !( defined( 'PHP7COMPAT_NO_DEPRECATIONS' ) && PHP7COMPAT_NO_DEPRECATIONS );
			if( $enabled ) trigger_error( $message, E_USER_DEPRECATED );
		}

		/**
		 * Raise the notice PHP gave on calling a deprecated function. When an
		 * alias calls its target, only the alias's own notice is raised.
		 */
		public static function deprecated_function( $function )
		{
			if( self::$alias === null ) self::deprecated( 'Function ' . $function . '() is deprecated' );
		}

		/**
		 * Call the function that $alias is an alias of. While it runs, its
		 * messages use the alias's name.
		 */
		public static function alias( $alias, $target, $args, $deprecated )
		{
			if( $deprecated ) self::deprecated( 'Function ' . $alias . '() is deprecated' );
			$previous = self::$alias;
			self::$alias = $alias;
			try
			{
				return call_user_func_array( $target, $args );
			}
			finally
			{
				self::$alias = $previous;
			}
		}

		/** Identifier used in messages that originally showed the resource number. */
		public static function id( $object )
		{
			return function_exists( 'spl_object_id' ) ? spl_object_id( $object ) : 0;
		}

		/** Create a map keyed by object, without keeping the keys alive if possible. */
		public static function object_map()
		{
			return class_exists( 'WeakMap', false ) ? new WeakMap() : new SplObjectStorage();
		}

		/**
		 * Run a mysqli call with mysqli's error reporting temporarily switched
		 * off, so that failures return false (as the original functions did)
		 * instead of throwing mysqli_sql_exception, which is the default from
		 * PHP 8.1. The application's own report mode is restored afterwards.
		 */
		public static function quiet( $function, $callback )
		{
			$driver = new mysqli_driver();
			$mode = $driver->report_mode;
			if( $mode !== MYSQLI_REPORT_OFF ) mysqli_report( MYSQLI_REPORT_OFF );
			try
			{
				return $callback();
			}
			catch( mysqli_sql_exception $e )
			{
				return false;
			}
			catch( ValueError $e )
			{
				self::warn( $function, $e->getMessage() );
				return false;
			}
			finally
			{
				if( $mode !== MYSQLI_REPORT_OFF ) mysqli_report( $mode );
			}
		}

		// ------------------------------------------------------------------
		// Links
		// ------------------------------------------------------------------

		/** Whether $link is a mysqli object with an open connection. */
		public static function link_is_open( $link )
		{
			if( !( $link instanceof mysqli ) ) return false;
			try
			{
				return is_int( @mysqli_thread_id( $link ) );
			}
			catch( Throwable $e )
			{
				return false;
			}
		}

		/** The current default link, or null if there isn't an open one. */
		public static function default_link()
		{
			$link = isset( $GLOBALS['_php7_compat_global_db_link'] ) ? $GLOBALS['_php7_compat_global_db_link'] : null;
			if( self::link_is_open( $link ) ) return $link;
			$GLOBALS['_php7_compat_global_db_link'] = null;
			return null;
		}

		/**
		 * Validate an explicitly passed link argument, which parse() has
		 * already checked is a resource or mysqli object.
		 *
		 * @return mysqli|false the link, or false (with warning) if it's
		 *         closed or not a link
		 */
		public static function given_link( $function, $link )
		{
			if( $link instanceof mysqli )
			{
				if( self::link_is_open( $link ) ) return $link;
				self::warn( $function, self::id( $link ) . ' is not a valid MySQL-Link resource' );
				return false;
			}
			self::warn( $function, 'supplied resource is not a valid MySQL-Link resource' );
			return false;
		}

		/**
		 * Resolve the link for a function with an optional link parameter.
		 *
		 * If the link was passed, it must be valid. If it was omitted, the
		 * default link is used; if there is none, a connection is attempted
		 * using the mysqli.default_* ini settings, as the original did with
		 * the mysql.default_* settings.
		 *
		 * @param bool $given whether the caller passed the link argument
		 * @return mysqli|false see given_link()
		 */
		public static function link( $function, $link, $given )
		{
			if( $given ) return self::given_link( $function, $link );
			$default = self::default_link();
			if( $default ) return $default;
			self::connect_notice( $function );
			$link = self::connect( $function, null, null, null, false, 0, false );
			if( !$link )
			{
				self::warn( $function, 'A link to the server could not be established' );
				return false;
			}
			return $link;
		}

		/** Forget a link that is being closed. */
		public static function forget_link( $link )
		{
			if( isset( $GLOBALS['_php7_compat_global_db_link'] ) && $GLOBALS['_php7_compat_global_db_link'] === $link )
			{
				$GLOBALS['_php7_compat_global_db_link'] = null;
			}
			foreach( self::$links as $key => $known )
			{
				if( $known === $link ) unset( self::$links[$key] );
			}
			if( self::$pending !== null && isset( self::$pending[$link] ) )
			{
				self::forget_result( self::$pending[$link] );
			}
		}

		/**
		 * Split a mysql_connect() server string into host, port and socket.
		 * Accepts "host", "host:port", "host:/socket", ":/socket" and
		 * "host:port:/socket", as the original did.
		 *
		 * @return array{0:string,1:int|null,2:string|null}
		 */
		public static function parse_server( $server )
		{
			$host = (string) $server;
			$port = null;
			$socket = null;
			$colon = strpos( $host, ':' );
			if( $colon !== false )
			{
				$rest = (string) substr( $host, $colon + 1 );
				$host = (string) substr( $host, 0, $colon );
				if( $rest !== '' && $rest[0] === '/' )
				{
					$socket = $rest;
				}
				else
				{
					$port = (int) $rest;
					if( $port === 0 ) $port = null;
					$colon = strpos( $rest, ':' );
					if( $colon !== false ) $socket = (string) substr( $rest, $colon + 1 );
				}
			}
			if( $host === '' ) $host = 'localhost';
			return array( $host, $port, $socket );
		}

		/**
		 * Open a connection, emulating mysql_connect()/mysql_pconnect().
		 * Null arguments fall back to the mysqli.default_* ini settings.
		 *
		 * @return mysqli|false
		 */
		/** The notice PHP 5.5+ raised on every connection attempt. */
		public static function connect_notice( $function )
		{
			self::deprecated( self::name( $function ) . '(): The mysql extension is deprecated and will be removed in the future: use mysqli or PDO instead' );
		}

		public static function connect( $function, $server, $username, $password, $new_link, $client_flags, $persistent )
		{
			if( $server === null ) $server = (string) ini_get( 'mysqli.default_host' );
			if( $username === null ) $username = (string) ini_get( 'mysqli.default_user' );
			if( $password === null ) $password = (string) ini_get( 'mysqli.default_pw' );
			$server = (string) $server;
			$username = (string) $username;
			$password = (string) $password;
			$client_flags = (int) $client_flags;

			$key = $server . "\0" . $username . "\0" . $password . "\0" . $client_flags;
			if( !$persistent && !$new_link && isset( self::$links[$key] ) )
			{
				if( self::link_is_open( self::$links[$key] ) )
				{
					$GLOBALS['_php7_compat_global_db_link'] = self::$links[$key];
					return self::$links[$key];
				}
				unset( self::$links[$key] );
			}

			list( $host, $port, $socket ) = self::parse_server( $server );
			if( $port === null )
			{
				$port = (int) ini_get( 'mysqli.default_port' );
				if( $port === 0 ) $port = 3306;
			}
			if( $socket === null )
			{
				$socket = (string) ini_get( 'mysqli.default_socket' );
				if( $socket === '' ) $socket = null;
			}
			if( $persistent ) $host = 'p:' . $host;

			$link = mysqli_init();
			$connected = self::quiet( $function, function() use ( $link, $host, $username, $password, $port, $socket, $client_flags )
			{
				return @mysqli_real_connect( $link, $host, $username, $password, null, $port, $socket, $client_flags );
			} );
			if( !$connected )
			{
				self::$connect_errno = (int) mysqli_connect_errno();
				self::$connect_error = (string) mysqli_connect_error();
				self::warn( $function, self::$connect_error !== '' ? self::$connect_error : 'Unable to connect to MySQL server' );
				return false;
			}

			self::$connect_errno = 0;
			self::$connect_error = null;
			if( !$persistent ) self::$links[$key] = $link;
			$GLOBALS['_php7_compat_global_db_link'] = $link;
			return $link;
		}

		// ------------------------------------------------------------------
		// Queries and unbuffered results
		// ------------------------------------------------------------------

		/**
		 * If the link has an unbuffered result with rows still unread, discard
		 * it with a notice, as the original did before running a new command.
		 * Without this, mysqli fails with "Commands out of sync".
		 */
		public static function flush_pending( $function, $link )
		{
			if( self::$pending === null || !isset( self::$pending[$link] ) ) return;
			$result = self::$pending[$link];
			self::forget_result( $result );
			trigger_error( self::name( $function ) . '(): Function called without first fetching all rows from a previous unbuffered query', E_USER_NOTICE );
			try
			{
				@mysqli_free_result( $result );
			}
			catch( Throwable $e )
			{
				// Already freed
			}
		}

		/**
		 * Run a query on $link.
		 *
		 * @return mysqli_result|bool
		 */
		public static function query( $function, $link, $query, $mode )
		{
			self::flush_pending( $function, $link );
			$query = (string) $query;
			// mysqli refuses empty queries outright; a blank query makes the
			// server report "Query was empty" (1065), as the original did.
			if( $query === '' ) $query = ' ';
			$result = self::quiet( $function, function() use ( $link, $query, $mode )
			{
				return mysqli_query( $link, $query, $mode );
			} );
			if( $result instanceof mysqli_result && $mode === MYSQLI_USE_RESULT )
			{
				if( self::$pending === null ) self::$pending = self::object_map();
				self::$pending[$link] = $result;
				self::set_cursor( $result, 0 );
			}
			return $result;
		}

		/** Select a database, as the original did inside several functions. */
		public static function select_db( $function, $link, $database )
		{
			self::flush_pending( $function, $link );
			return (bool) self::quiet( $function, function() use ( $link, $database )
			{
				return mysqli_select_db( $link, (string) $database );
			} );
		}

		/** Quote an identifier for use in SQL. */
		public static function quote_identifier( $name )
		{
			return '`' . str_replace( '`', '``', (string) $name ) . '`';
		}

		// ------------------------------------------------------------------
		// Results
		// ------------------------------------------------------------------

		/** Whether $result is a mysqli_result that hasn't been freed. */
		public static function result_is_open( $result )
		{
			try
			{
				return is_int( @mysqli_num_fields( $result ) );
			}
			catch( Throwable $e )
			{
				return false;
			}
		}

		/**
		 * Validate a result argument. Usually parse() has already checked it
		 * is a resource or mysqli object; mysql_fetch_object() accepted any
		 * value, which is why a non-resource gets its own message.
		 *
		 * @return bool true if valid; false (with warning) otherwise
		 */
		public static function result( $function, $result )
		{
			if( $result instanceof mysqli_result )
			{
				if( self::result_is_open( $result ) ) return true;
				self::warn( $function, self::id( $result ) . ' is not a valid MySQL result resource' );
				return false;
			}
			if( is_object( $result ) || is_resource( $result ) ) self::warn( $function, 'supplied resource is not a valid MySQL result resource' );
			else self::warn( $function, 'supplied argument is not a valid MySQL result resource' );
			return false;
		}

		/** Whether $result is unbuffered (from mysql_unbuffered_query()). */
		public static function is_unbuffered( $result )
		{
			return $result->type === MYSQLI_USE_RESULT;
		}

		/** Whether $result is unbuffered and still has unread rows. */
		public static function is_pending( $result )
		{
			if( self::$pending === null ) return false;
			foreach( self::$pending as $link => $pending )
			{
				// SplObjectStorage iterates keys as values, WeakMap as keys
				if( $pending === $result || ( self::$pending instanceof SplObjectStorage && self::$pending[$pending] === $result ) ) return true;
			}
			return false;
		}

		/** Forget all state about a result that is being freed or discarded. */
		public static function forget_result( $result )
		{
			if( self::$pending !== null )
			{
				$links = array();
				foreach( self::$pending as $key => $value )
				{
					if( self::$pending instanceof SplObjectStorage )
					{
						if( self::$pending[$value] === $result ) $links[] = $value;
					}
					elseif( $value === $result )
					{
						$links[] = $key;
					}
				}
				foreach( $links as $link ) unset( self::$pending[$link] );
			}
			if( self::$cursors !== null && isset( self::$cursors[$result] ) ) unset( self::$cursors[$result] );
			if( self::$max_lengths !== null && isset( self::$max_lengths[$result] ) ) unset( self::$max_lengths[$result] );
			if( self::$list_defaults !== null && isset( self::$list_defaults[$result] ) ) unset( self::$list_defaults[$result] );
		}

		/**
		 * Whether to track the row cursor of $result. mysqli doesn't expose
		 * the cursor position, but we need it for:
		 *  - unbuffered results, so mysql_num_rows() can return the number of
		 *    rows fetched so far, as the original did;
		 *  - buffered results on PHP 8.1+, where mysqli no longer computes
		 *    max_length, so mysql_fetch_field() has to scan the rows itself
		 *    and then restore the cursor.
		 */
		public static function track_cursor( $result )
		{
			return PHP_VERSION_ID >= 80100 || self::is_unbuffered( $result );
		}

		public static function set_cursor( $result, $position )
		{
			if( self::$saved_lengths !== null && isset( self::$saved_lengths[$result] ) ) unset( self::$saved_lengths[$result] );
			if( !self::track_cursor( $result ) ) return;
			if( self::$cursors === null ) self::$cursors = self::object_map();
			self::$cursors[$result] = $position;
		}

		public static function get_cursor( $result )
		{
			return ( self::$cursors !== null && isset( self::$cursors[$result] ) ) ? self::$cursors[$result] : 0;
		}

		/**
		 * Shared implementation of mysql_fetch_array(), mysql_fetch_assoc()
		 * and mysql_fetch_row(). All three accepted an optional result type,
		 * which overrides the function's usual one; 0 means MYSQL_BOTH, and
		 * an invalid type warns and falls back to MYSQL_BOTH.
		 */
		public static function fetch_hash( $function, $args, $default_type )
		{
			if( !self::parse( $function, $args, 'r|l' ) ) return null;
			$type = count( $args ) > 1 ? (int) $args[1] : $default_type;
			if( !$type ) $type = MYSQL_BOTH;
			if( $type & ~MYSQL_BOTH )
			{
				self::warn( $function, 'The result type should be either MYSQL_NUM, MYSQL_ASSOC or MYSQL_BOTH' );
				$type = MYSQL_BOTH;
			}
			$result = $args[0];
			if( !self::result( $function, $result ) ) return false;
			return self::fetch( $function, $result, function( $result ) use ( $type )
			{
				return mysqli_fetch_array( $result, $type );
			} );
		}

		/**
		 * Fetch a row with $callback, converting mysqli's null at the end of
		 * the result set into the original's false, and tracking the cursor.
		 */
		public static function fetch( $function, $result, $callback )
		{
			if( self::$saved_lengths !== null && isset( self::$saved_lengths[$result] ) ) unset( self::$saved_lengths[$result] );
			$row = self::quiet( $function, function() use ( $result, $callback )
			{
				return $callback( $result );
			} );
			if( $row === null || $row === false )
			{
				if( self::is_unbuffered( $result ) && self::is_pending( $result ) )
				{
					// All rows read: the link is free for new queries, and
					// mysqli can report the row count itself from now on.
					$count = self::get_cursor( $result );
					self::forget_result( $result );
					self::set_cursor( $result, $count );
				}
				return false;
			}
			if( self::track_cursor( $result ) ) self::set_cursor( $result, self::get_cursor( $result ) + 1 );
			return $row;
		}

		/** Number of rows in a buffered result, or -1 if unbuffered. */
		public static function buffered_rows( $result )
		{
			if( self::is_unbuffered( $result ) ) return -1;
			return (int) mysqli_num_rows( $result );
		}

		// ------------------------------------------------------------------
		// Fields
		// ------------------------------------------------------------------

		/**
		 * Seek to and fetch a field's metadata for the mysql_field_*()
		 * functions, warning if the offset is out of range. Like the
		 * original, this leaves the field cursor after the requested field.
		 *
		 * @return object|false
		 */
		public static function field_info( $function, $result, $offset )
		{
			$offset = (int) $offset;
			if( $offset < 0 || $offset >= mysqli_num_fields( $result ) )
			{
				self::warn( $function, 'Field ' . $offset . ' is invalid for MySQL result index ' . self::id( $result ) );
				return false;
			}
			mysqli_field_seek( $result, $offset );
			$field = mysqli_fetch_field( $result );
			return $field ? $field : false;
		}

		/** Map a MySQL column type number to the names used by the original. */
		public static function type_name( $type )
		{
			switch( $type )
			{
				case 253: // VAR_STRING
				case 254: // STRING
					return 'string';
				case 16: // BIT
				case 1: // TINY
				case 2: // SHORT
				case 3: // LONG
				case 8: // LONGLONG
				case 9: // INT24
					return 'int';
				case 0: // DECIMAL
				case 4: // FLOAT
				case 5: // DOUBLE
				case 246: // NEWDECIMAL
					return 'real';
				case 7:
					return 'timestamp';
				case 13:
					return 'year';
				case 10: // DATE
				case 14: // NEWDATE
					return 'date';
				case 11:
					return 'time';
				case 248:
					return 'set';
				case 247:
					return 'enum';
				case 255:
					return 'geometry';
				case 12:
					return 'datetime';
				case 249: // TINY_BLOB
				case 250: // MEDIUM_BLOB
				case 251: // LONG_BLOB
				case 252: // BLOB
					return 'blob';
				case 6:
					return 'null';
				default:
					return 'unknown';
			}
		}

		/** The flag names used by mysql_field_flags(), in the original's order. */
		public static function flag_names( $flags )
		{
			$names = array(
				1 => 'not_null',
				2 => 'primary_key',
				4 => 'unique_key',
				8 => 'multiple_key',
				16 => 'blob',
				32 => 'unsigned',
				64 => 'zerofill',
				128 => 'binary',
				256 => 'enum',
				2048 => 'set',
				512 => 'auto_increment',
				1024 => 'timestamp',
			);
			$set = array();
			foreach( $names as $bit => $name )
			{
				if( $flags & $bit ) $set[] = $name;
			}
			return implode( ' ', $set );
		}

		/** The IS_NUM() macro from mysqlnd, which the original was built with. */
		public static function is_numeric_type( $type )
		{
			return $type <= 9 || $type === 13 || $type === 246;
		}

		/**
		 * The longest value in each column of a buffered result. mysqli
		 * stopped computing this in PHP 8.1, so scan the rows ourselves, then
		 * put the row cursor back where it was.
		 *
		 * @return int[]
		 */
		public static function max_lengths( $result )
		{
			if( self::$max_lengths !== null && isset( self::$max_lengths[$result] ) ) return self::$max_lengths[$result];

			$lengths = array_fill( 0, mysqli_num_fields( $result ), 0 );
			$rows = (int) mysqli_num_rows( $result );
			if( $rows > 0 )
			{
				$position = self::get_cursor( $result );
				// The scan changes mysqli's idea of the current row's lengths,
				// so keep what mysql_fetch_lengths() should return until the
				// cursor next moves
				if( self::$saved_lengths === null ) self::$saved_lengths = self::object_map();
				self::$saved_lengths[$result] = mysqli_fetch_lengths( $result );
				mysqli_data_seek( $result, 0 );
				while( mysqli_fetch_row( $result ) !== null )
				{
					foreach( mysqli_fetch_lengths( $result ) as $i => $length )
					{
						if( $length > $lengths[$i] ) $lengths[$i] = $length;
					}
				}
				// After the scan the cursor is at the end, which is correct if
				// it was there before.
				if( $position < $rows ) mysqli_data_seek( $result, $position );
			}

			if( self::$max_lengths === null ) self::$max_lengths = self::object_map();
			self::$max_lengths[$result] = $lengths;
			return $lengths;
		}

		/**
		 * The default values the original reported in mysql_fetch_field()->def
		 * for mysql_list_fields() results: the server's default row for the
		 * table, as strings. mysqli can't send the protocol command that
		 * returned these, so they're worked out from SHOW COLUMNS.
		 *
		 * @return string[]|null in column order, or null if unavailable
		 */
		public static function column_defaults( $function, $link, $table )
		{
			$columns = self::quiet( $function, function() use ( $link, $table )
			{
				return mysqli_query( $link, 'SHOW COLUMNS FROM ' . Php7Compat::quote_identifier( $table ) );
			} );
			if( !( $columns instanceof mysqli_result ) ) return null;
			$defaults = array();
			while( ( $column = mysqli_fetch_assoc( $columns ) ) !== null )
			{
				$defaults[] = self::column_default( $column );
			}
			mysqli_free_result( $columns );
			return $defaults;
		}

		/** One column's default value, from a SHOW COLUMNS row. */
		private static function column_default( $column )
		{
			$default = $column['Default'];
			$is_expression = $default !== null && ( preg_match( '/^current_timestamp(\(\d*\))?$/i', $default ) || stripos( $column['Extra'], 'DEFAULT_GENERATED' ) !== false );
			if( $default === null || $is_expression )
			{
				// No literal default: NULL if the column allows it, otherwise
				// the type's implicit default
				if( $column['Null'] === 'YES' ) return '';
				return self::implicit_default( $column['Type'] );
			}
			if( preg_match( '/^bit\((\d+)\)/i', $column['Type'], $match ) && preg_match( "/^b'([01]*)'$/", $default, $bits ) )
			{
				// BIT defaults are shown as b'101' but were sent as raw bytes
				$bytes = (int) ceil( (int) $match[1] / 8 );
				$value = $bits[1] === '' ? 0 : bindec( $bits[1] );
				$out = '';
				for( $i = 0; $i < $bytes; $i++ )
				{
					$out = chr( $value % 256 ) . $out;
					$value = intdiv( $value, 256 );
				}
				return $out;
			}
			return $default;
		}

		/** The value a NOT NULL column with no default gets, by column type. */
		private static function implicit_default( $type )
		{
			$type = strtolower( $type );
			$fraction = preg_match( '/^(?:datetime|timestamp|time)\((\d+)\)/', $type, $match ) && (int) $match[1] > 0 ? '.' . str_repeat( '0', (int) $match[1] ) : '';
			if( preg_match( '/^(?:decimal|numeric|dec|fixed)\(\d+,(\d+)\)/', $type, $match ) ) return (int) $match[1] > 0 ? '0.' . str_repeat( '0', (int) $match[1] ) : '0';
			if( preg_match( '/^(?:tinyint|smallint|mediumint|int|integer|bigint|float|double|real|decimal|numeric|dec|fixed|bool|boolean)\b/', $type ) ) return '0';
			if( preg_match( '/^year\b/', $type ) ) return '0000';
			if( preg_match( '/^date\b/', $type ) ) return '0000-00-00';
			if( preg_match( '/^(?:datetime|timestamp)\b/', $type ) ) return '0000-00-00 00:00:00' . $fraction;
			if( preg_match( '/^time\b/', $type ) ) return '00:00:00' . $fraction;
			if( preg_match( "/^enum\\('((?:[^']|'')*)'/", $type, $match ) ) return str_replace( "''", "'", $match[1] );
			if( preg_match( '/^bit\((\d+)\)/', $type, $match ) ) return str_repeat( "\0", (int) ceil( (int) $match[1] / 8 ) );
			if( preg_match( '/^binary\((\d+)\)/', $type, $match ) ) return str_repeat( "\0", (int) $match[1] );
			return '';
		}

		/** Build the object returned by the original mysql_fetch_field(). */
		public static function legacy_field( $result, $field, $index )
		{
			if( self::is_unbuffered( $result ) ) $max_length = 0;
			elseif( PHP_VERSION_ID >= 80100 )
			{
				$lengths = self::max_lengths( $result );
				$max_length = $lengths[$index];
			}
			else $max_length = (int) $field->max_length;

			$object = new stdClass();
			$object->name = $field->name;
			$object->table = $field->table;
			$object->def = ( self::$list_defaults !== null && isset( self::$list_defaults[$result] ) ) ? self::$list_defaults[$result][$index] : '';
			$object->max_length = $max_length;
			$object->not_null = ( $field->flags & 1 ) ? 1 : 0;
			$object->primary_key = ( $field->flags & 2 ) ? 1 : 0;
			$object->multiple_key = ( $field->flags & 8 ) ? 1 : 0;
			$object->unique_key = ( $field->flags & 4 ) ? 1 : 0;
			$object->numeric = self::is_numeric_type( $field->type ) ? 1 : 0;
			$object->blob = ( $field->flags & 16 ) ? 1 : 0;
			$object->type = self::type_name( $field->type );
			$object->unsigned = ( $field->flags & 32 ) ? 1 : 0;
			$object->zerofill = ( $field->flags & 64 ) ? 1 : 0;
			return $object;
		}

		// ------------------------------------------------------------------
		// Magic quotes
		// ------------------------------------------------------------------

		/**
		 * Shared implementation of set_magic_quotes_runtime() and its alias,
		 * which always return false. Turning magic quotes on can't be done:
		 * PHP 5.4+ raised a fatal error, but halting the script isn't helpful
		 * in a compatibility layer, so warn with the same message instead.
		 */
		public static function set_magic_quotes_runtime( $function, $new_setting )
		{
			if( $new_setting ) self::warn( $function, 'magic_quotes_runtime is not supported anymore' );
			return false;
		}

		// ------------------------------------------------------------------
		// POSIX regular expressions (ereg)
		// ------------------------------------------------------------------

		/** Character classes supported by the original regex library. */
		private static $ctypes = array( 'alnum', 'alpha', 'blank', 'cntrl', 'digit', 'graph', 'lower', 'print', 'punct', 'space', 'upper', 'xdigit' );

		/** Named characters for [.name.] and [=name=], from the original library's cname.h. */
		private static $collating_names = array(
			'NUL' => "\x00", 'SOH' => "\x01", 'STX' => "\x02", 'ETX' => "\x03", 'EOT' => "\x04", 'ENQ' => "\x05",
			'ACK' => "\x06", 'BEL' => "\x07", 'alert' => "\x07", 'BS' => "\x08", 'backspace' => "\x08",
			'HT' => "\x09", 'tab' => "\x09", 'LF' => "\x0a", 'newline' => "\x0a", 'VT' => "\x0b",
			'vertical-tab' => "\x0b", 'FF' => "\x0c", 'form-feed' => "\x0c", 'CR' => "\x0d",
			'carriage-return' => "\x0d", 'SO' => "\x0e", 'SI' => "\x0f", 'DLE' => "\x10", 'DC1' => "\x11",
			'DC2' => "\x12", 'DC3' => "\x13", 'DC4' => "\x14", 'NAK' => "\x15", 'SYN' => "\x16", 'ETB' => "\x17",
			'CAN' => "\x18", 'EM' => "\x19", 'SUB' => "\x1a", 'ESC' => "\x1b", 'IS4' => "\x1c", 'FS' => "\x1c",
			'IS3' => "\x1d", 'GS' => "\x1d", 'IS2' => "\x1e", 'RS' => "\x1e", 'IS1' => "\x1f", 'US' => "\x1f",
			'space' => ' ', 'exclamation-mark' => '!', 'quotation-mark' => "\x22", 'number-sign' => '#',
			'dollar-sign' => "\x24", 'percent-sign' => '%', 'ampersand' => '&', 'apostrophe' => '\'',
			'left-parenthesis' => '(', 'right-parenthesis' => ')', 'asterisk' => '*', 'plus-sign' => '+',
			'comma' => ',', 'hyphen' => '-', 'hyphen-minus' => '-', 'period' => '.', 'full-stop' => '.',
			'slash' => '/', 'solidus' => '/', 'zero' => '0', 'one' => '1', 'two' => '2', 'three' => '3',
			'four' => '4', 'five' => '5', 'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9', 'colon' => ':',
			'semicolon' => ';', 'less-than-sign' => '<', 'equals-sign' => '=', 'greater-than-sign' => '>',
			'question-mark' => '?', 'commercial-at' => '@', 'left-square-bracket' => '[', 'backslash' => "\x5c",
			'reverse-solidus' => "\x5c", 'right-square-bracket' => ']', 'circumflex' => '^',
			'circumflex-accent' => '^', 'underscore' => '_', 'low-line' => '_', 'grave-accent' => '`',
			'left-brace' => '{', 'left-curly-bracket' => '{', 'vertical-line' => '|', 'right-brace' => '}',
			'right-curly-bracket' => '}', 'tilde' => '~', 'DEL' => "\x7f"
		);

		/**
		 * Compile a POSIX extended regular expression to PCRE, warning (as
		 * the original did) if it is invalid.
		 *
		 * @return array{pcre:string,groups:int}|false
		 */
		public static function compile( $function, $pattern, $icase )
		{
			if( !is_string( $pattern ) ) $pattern = chr( (int) $pattern );
			$pattern = self::c_string( $pattern );
			$key = ( $icase ? 'i' : 'c' ) . $pattern;
			if( !isset( self::$regex_cache[$key] ) )
			{
				if( count( self::$regex_cache ) >= 4096 ) self::$regex_cache = array();
				try
				{
					$groups = 0;
					$pcre = '/' . self::translate( $pattern, $groups ) . '/sD' . ( $icase ? 'i' : '' );
					if( @preg_match( $pcre, '' ) === false ) throw new RuntimeException( 'REG_BADPAT' );
					self::$regex_cache[$key] = array( 'pcre' => $pcre, 'groups' => $groups );
				}
				catch( RuntimeException $e )
				{
					self::$regex_cache[$key] = array( 'error' => $e->getMessage() );
				}
			}
			$compiled = self::$regex_cache[$key];
			if( isset( $compiled['error'] ) )
			{
				self::warn( $function, $compiled['error'] );
				return false;
			}
			return $compiled;
		}

		/**
		 * A string as the original C code saw it: everything up to the first
		 * NUL byte.
		 */
		private static function c_string( $string )
		{
			$nul = strpos( $string, "\0" );
			return $nul === false ? $string : (string) substr( $string, 0, $nul );
		}

		/** A PCRE-safe representation of a single literal byte. */
		private static function literal( $char )
		{
			if( ( $char >= '0' && $char <= '9' ) || ( $char >= 'a' && $char <= 'z' ) || ( $char >= 'A' && $char <= 'Z' ) ) return $char;
			return '\\x' . bin2hex( $char );
		}

		/**
		 * Translate POSIX ERE syntax, as implemented by Henry Spencer's regex
		 * library used by the original ereg functions, into PCRE syntax.
		 * Throws RuntimeException with the original's error code if invalid.
		 *
		 * @param int $groups set to the number of capturing groups
		 */
		private static function translate( $pattern, &$groups )
		{
			$length = strlen( $pattern );
			$out = '';
			$i = 0;
			$depth = 0;
			$branch_empty = array( true );
			// What the previous token was: 'start' (start of a branch),
			// 'atom' (can be repeated), 'repeat', or 'caret'.
			$previous = 'start';
			$groups = 0;

			while( $i < $length )
			{
				$char = $pattern[$i];
				switch( $char )
				{
					case '|':
						if( $branch_empty[$depth] ) throw new RuntimeException( 'REG_EMPTY' );
						$out .= '|';
						$branch_empty[$depth] = true;
						$previous = 'start';
						$i++;
						continue 2;

					case '(':
						$groups++;
						$i++;
						if( $i < $length && $pattern[$i] === ')' )
						{
							// An empty group is allowed
							$out .= '()';
							$i++;
							break;
						}
						$out .= '(';
						$depth++;
						$branch_empty[$depth] = true;
						$previous = 'start';
						continue 2;

					case ')':
						if( $depth === 0 ) throw new RuntimeException( 'REG_EPAREN' );
						if( $branch_empty[$depth] ) throw new RuntimeException( 'REG_EMPTY' );
						$out .= ')';
						$depth--;
						$i++;
						break;

					case '*':
					case '+':
					case '?':
						if( $previous !== 'atom' ) throw new RuntimeException( 'REG_BADRPT' );
						$out .= $char;
						$previous = 'repeat';
						$i++;
						continue 2;

					case '{':
						if( $i + 1 < $length && $pattern[$i + 1] >= '0' && $pattern[$i + 1] <= '9' )
						{
							if( $previous !== 'atom' ) throw new RuntimeException( 'REG_BADRPT' );
							if( !preg_match( '/\G\{([0-9]+)(,([0-9]*))?\}/', $pattern, $match, 0, $i ) )
							{
								throw new RuntimeException( strpos( $pattern, '}', $i ) === false ? 'REG_EBRACE' : 'REG_BADBR' );
							}
							$min = (int) $match[1];
							$max = isset( $match[3] ) && $match[3] !== '' ? (int) $match[3] : null;
							if( $min > 255 || ( $max !== null && ( $max > 255 || $max < $min ) ) ) throw new RuntimeException( 'REG_BADBR' );
							$out .= $match[0];
							$previous = 'repeat';
							$i += strlen( $match[0] );
							continue 2;
						}
						$out .= self::literal( '{' );
						$i++;
						break;

					case '^':
						$out .= '^';
						$branch_empty[$depth] = false;
						$previous = 'caret';
						$i++;
						continue 2;

					case '$':
						// Grouped, because the original allowed $ to be
						// repeated (e.g. a$*) and PCRE doesn't
						$out .= '(?:$)';
						$i++;
						break;

					case '.':
						$out .= '.';
						$i++;
						break;

					case '\\':
						if( $i + 1 >= $length ) throw new RuntimeException( 'REG_EESCAPE' );
						$out .= self::literal( $pattern[$i + 1] );
						$i += 2;
						break;

					case '[':
						if( substr( $pattern, $i, 7 ) === '[[:<:]]' )
						{
							$out .= '\\b(?=\\w)';
							$i += 7;
						}
						elseif( substr( $pattern, $i, 7 ) === '[[:>:]]' )
						{
							$out .= '\\b(?<=\\w)';
							$i += 7;
						}
						else
						{
							$out .= self::translate_bracket( $pattern, $i );
						}
						break;

					default:
						$out .= self::literal( $char );
						$i++;
						break;
				}
				$branch_empty[$depth] = false;
				$previous = 'atom';
			}

			if( $depth > 0 ) throw new RuntimeException( 'REG_EPAREN' );
			if( $branch_empty[0] ) throw new RuntimeException( 'REG_EMPTY' );
			return $out;
		}

		/**
		 * Translate a bracket expression starting at $pattern[$i], advancing
		 * $i past it. This follows p_bracket() and p_b_term() in the original
		 * library: backslash is an ordinary character, a ] or - at the start
		 * and a - at the end are literal, and any other - must form a range.
		 */
		private static function translate_bracket( $pattern, &$i )
		{
			$length = strlen( $pattern );
			$i++;
			$out = '[';
			if( $i < $length && $pattern[$i] === '^' )
			{
				$out .= '^';
				$i++;
			}
			if( $i < $length && ( $pattern[$i] === ']' || $pattern[$i] === '-' ) )
			{
				$out .= self::literal( $pattern[$i] );
				$i++;
			}
			while( $i < $length && $pattern[$i] !== ']' && !( $pattern[$i] === '-' && $i + 1 < $length && $pattern[$i + 1] === ']' ) )
			{
				$out .= self::bracket_term( $pattern, $i );
			}
			if( $i < $length && $pattern[$i] === '-' )
			{
				$out .= self::literal( '-' );
				$i++;
			}
			if( $i >= $length ) throw new RuntimeException( 'REG_EBRACK' );
			$i++;
			return $out . ']';
		}

		/** Translate one term of a bracket expression: a class, equivalence class, character or range. */
		private static function bracket_term( $pattern, &$i )
		{
			$length = strlen( $pattern );
			if( $pattern[$i] === '-' ) throw new RuntimeException( 'REG_ERANGE' );
			$next = $i + 1 < $length ? $pattern[$i + 1] : '';

			if( $pattern[$i] === '[' && $next === ':' )
			{
				$i += 2;
				if( $i >= $length ) throw new RuntimeException( 'REG_EBRACK' );
				if( $pattern[$i] === '-' || $pattern[$i] === ']' ) throw new RuntimeException( 'REG_ECTYPE' );
				$name = '';
				while( $i < $length && ( ( $pattern[$i] >= 'a' && $pattern[$i] <= 'z' ) || ( $pattern[$i] >= 'A' && $pattern[$i] <= 'Z' ) ) ) $name .= $pattern[$i++];
				if( !in_array( $name, self::$ctypes, true ) ) throw new RuntimeException( 'REG_ECTYPE' );
				if( $i >= $length ) throw new RuntimeException( 'REG_EBRACK' );
				if( substr( $pattern, $i, 2 ) !== ':]' ) throw new RuntimeException( 'REG_ECTYPE' );
				$i += 2;
				return '[:' . $name . ':]';
			}

			if( $pattern[$i] === '[' && $next === '=' )
			{
				$i += 2;
				if( $i >= $length ) throw new RuntimeException( 'REG_EBRACK' );
				if( $pattern[$i] === '-' || $pattern[$i] === ']' ) throw new RuntimeException( 'REG_ECOLLATE' );
				$char = self::collating_element( $pattern, $i, '=' );
				$i += 2;
				return self::literal( $char );
			}

			$start = self::bracket_symbol( $pattern, $i );
			if( $i + 1 < $length && $pattern[$i] === '-' && $pattern[$i + 1] !== ']' )
			{
				$i++;
				if( $pattern[$i] === '-' )
				{
					$end = '-';
					$i++;
				}
				else
				{
					$end = self::bracket_symbol( $pattern, $i );
				}
				if( ord( $start ) > ord( $end ) ) throw new RuntimeException( 'REG_ERANGE' );
				return self::literal( $start ) . '-' . self::literal( $end );
			}
			return self::literal( $start );
		}

		/** Read a single character or a [.collating.] element. */
		private static function bracket_symbol( $pattern, &$i )
		{
			if( $i >= strlen( $pattern ) ) throw new RuntimeException( 'REG_EBRACK' );
			if( substr( $pattern, $i, 2 ) !== '[.' ) return $pattern[$i++];
			$i += 2;
			$char = self::collating_element( $pattern, $i, '.' );
			$i += 2;
			return $char;
		}

		/**
		 * Read a collating element name up to (not including) $end followed
		 * by ], and return its character: a named character from the
		 * original's table, or a single character standing for itself.
		 */
		private static function collating_element( $pattern, &$i, $end )
		{
			$close = strpos( $pattern, $end . ']', $i );
			if( $close === false ) throw new RuntimeException( 'REG_EBRACK' );
			$name = (string) substr( $pattern, $i, $close - $i );
			$i = $close;
			if( isset( self::$collating_names[$name] ) ) return self::$collating_names[$name];
			if( strlen( $name ) === 1 ) return $name;
			throw new RuntimeException( 'REG_ECOLLATE' );
		}

		/** Shared implementation of ereg() and eregi(). */
		public static function ereg( $function, $pattern, $string, &$regs, $want_regs, $icase )
		{
			$compiled = self::compile( $function, $pattern, $icase );
			if( $compiled === false ) return false;
			if( !preg_match( $compiled['pcre'], self::c_string( (string) $string ), $match ) ) return false;
			if( !$want_regs ) return 1;

			$regs = array();
			for( $group = 0; $group <= $compiled['groups']; $group++ )
			{
				$regs[$group] = ( isset( $match[$group] ) && $match[$group] !== '' ) ? $match[$group] : false;
			}
			$length = strlen( $match[0] );
			return $length > 0 ? $length : 1;
		}

		/** Shared implementation of ereg_replace() and eregi_replace(). */
		public static function ereg_replace( $function, $pattern, $replacement, $string, $icase )
		{
			$compiled = self::compile( $function, $pattern, $icase );
			if( $compiled === false ) return false;
			if( !is_string( $replacement ) ) $replacement = chr( (int) $replacement );
			$replacement = self::c_string( $replacement );

			// Only \0 to \N (where N is the number of groups) are special in
			// the replacement; everything else, including $, is literal.
			$parts = array();
			$literal = '';
			$length = strlen( $replacement );
			for( $i = 0; $i < $length; $i++ )
			{
				$char = $replacement[$i];
				if( $char === '\\' && $i + 1 < $length && $replacement[$i + 1] >= '0' && $replacement[$i + 1] <= '9' && (int) $replacement[$i + 1] <= $compiled['groups'] )
				{
					if( $literal !== '' ) $parts[] = $literal;
					$literal = '';
					$parts[] = (int) $replacement[++$i];
				}
				else
				{
					$literal .= $char;
				}
			}
			if( $literal !== '' ) $parts[] = $literal;

			$result = preg_replace_callback( $compiled['pcre'], function( $match ) use ( $parts )
			{
				$out = '';
				foreach( $parts as $part )
				{
					if( is_int( $part ) ) $out .= isset( $match[$part] ) ? $match[$part] : '';
					else $out .= $part;
				}
				return $out;
			}, self::c_string( (string) $string ) );
			return $result === null ? false : $result;
		}

		/** Shared implementation of split() and spliti(). */
		public static function split( $function, $pattern, $string, $limit, $icase )
		{
			$compiled = self::compile( $function, $pattern, $icase );
			if( $compiled === false ) return false;
			$string = (string) $string;
			$limit = (int) $limit;

			// Like the original, run the regex against the remainder of the
			// string each time, so ^ matches at the start of each piece.
			$pieces = array();
			$position = 0;
			while( $limit === -1 || $limit > 1 )
			{
				// The search stops at a NUL byte, but the last piece keeps
				// everything after it
				$rest = (string) substr( $string, $position );
				if( !preg_match( $compiled['pcre'], self::c_string( $rest ), $match, PREG_OFFSET_CAPTURE ) ) break;
				$start = $match[0][1];
				$end = $start + strlen( $match[0][0] );
				if( $start === 0 && $end === 0 )
				{
					self::warn( $function, 'Invalid Regular Expression' );
					return false;
				}
				$pieces[] = (string) substr( $rest, 0, $start );
				$position += $end;
				if( $limit !== -1 ) $limit--;
			}
			$pieces[] = (string) substr( $string, $position );
			return $pieces;
		}
	}
}

// ----------------------------------------------------------------------
// Connections
// ----------------------------------------------------------------------

if( !function_exists( 'mysql_connect' ) )
{
	function mysql_connect( $server = null, $username = null, $password = null, $new_link = false, $client_flags = 0 )
	{
		Php7Compat::connect_notice( __FUNCTION__ );
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), '|s!s!s!bl' ) ) return null;
		return Php7Compat::connect( __FUNCTION__, $server, $username, $password, $new_link, $client_flags, false );
	}
}

if( !function_exists( 'mysql_pconnect' ) )
{
	function mysql_pconnect( $server = null, $username = null, $password = null, $client_flags = 0 )
	{
		Php7Compat::connect_notice( __FUNCTION__ );
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), '|s!s!s!l' ) ) return null;
		return Php7Compat::connect( __FUNCTION__, $server, $username, $password, true, $client_flags, true );
	}
}

if( !function_exists( 'mysql_close' ) )
{
	function mysql_close( $link_identifier = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), '|r' ) ) return null;
		if( func_num_args() > 0 )
		{
			$link = Php7Compat::given_link( __FUNCTION__, $link_identifier );
			if( !$link ) return $link;
		}
		else
		{
			$link = Php7Compat::default_link();
			if( !$link )
			{
				Php7Compat::warn( __FUNCTION__, 'no MySQL-Link resource supplied' );
				return false;
			}
		}
		Php7Compat::flush_pending( __FUNCTION__, $link );
		Php7Compat::forget_link( $link );
		try
		{
			@mysqli_close( $link );
		}
		catch( Throwable $e )
		{
			return false;
		}
		return true;
	}
}

if( !function_exists( 'mysql_ping' ) )
{
	function mysql_ping( $link_identifier = null )
	{
		if( func_num_args() > 0 && !Php7Compat::parse( __FUNCTION__, func_get_args(), 'r' ) ) return null;
		$link = Php7Compat::link( __FUNCTION__, $link_identifier, func_num_args() > 0 );
		if( !$link ) return $link;
		Php7Compat::flush_pending( __FUNCTION__, $link );
		return (bool) Php7Compat::quiet( __FUNCTION__, function() use ( $link )
		{
			// mysqli_ping() is deprecated as of PHP 8.4
			if( PHP_VERSION_ID < 80400 ) return @mysqli_ping( $link );
			return @mysqli_query( $link, 'DO 1' );
		} );
	}
}

// ----------------------------------------------------------------------
// Queries
// ----------------------------------------------------------------------

if( !function_exists( 'mysql_query' ) )
{
	function mysql_query( $query = null, $link_identifier = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 's|r' ) ) return null;
		$link = Php7Compat::link( __FUNCTION__, $link_identifier, func_num_args() > 1 );
		if( !$link ) return $link;
		return Php7Compat::query( __FUNCTION__, $link, $query, MYSQLI_STORE_RESULT );
	}
}

if( !function_exists( 'mysql_unbuffered_query' ) )
{
	function mysql_unbuffered_query( $query = null, $link_identifier = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 's|r' ) ) return null;
		$link = Php7Compat::link( __FUNCTION__, $link_identifier, func_num_args() > 1 );
		if( !$link ) return $link;
		return Php7Compat::query( __FUNCTION__, $link, $query, MYSQLI_USE_RESULT );
	}
}

if( !function_exists( 'mysql_db_query' ) )
{
	function mysql_db_query( $database = null, $query = null, $link_identifier = null )
	{
		Php7Compat::deprecated_function( __FUNCTION__ );
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 'ss|r' ) ) return null;
		$given = func_num_args() > 2;
		$notice = Php7Compat::name( __FUNCTION__ ) . '(): This function is deprecated; use mysql_query() instead';
		if( $given ) Php7Compat::deprecated( $notice );
		$link = Php7Compat::link( __FUNCTION__, $link_identifier, $given );
		if( !$link ) return $link;
		if( !$given ) Php7Compat::deprecated( $notice );
		if( !Php7Compat::select_db( __FUNCTION__, $link, $database ) ) return false;
		return Php7Compat::query( __FUNCTION__, $link, $query, MYSQLI_STORE_RESULT );
	}
}

if( !function_exists( 'mysql_select_db' ) )
{
	function mysql_select_db( $database_name = null, $link_identifier = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 's|r' ) ) return null;
		$link = Php7Compat::link( __FUNCTION__, $link_identifier, func_num_args() > 1 );
		if( !$link ) return $link;
		return Php7Compat::select_db( __FUNCTION__, $link, $database_name );
	}
}

if( !function_exists( 'mysql_create_db' ) )
{
	function mysql_create_db( $database_name = null, $link_identifier = null )
	{
		Php7Compat::deprecated_function( __FUNCTION__ );
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 's|r' ) ) return null;
		$link = Php7Compat::link( __FUNCTION__, $link_identifier, func_num_args() > 1 );
		if( !$link ) return $link;
		return Php7Compat::query( __FUNCTION__, $link, 'CREATE DATABASE ' . Php7Compat::quote_identifier( $database_name ), MYSQLI_STORE_RESULT ) === true;
	}
}

if( !function_exists( 'mysql_drop_db' ) )
{
	function mysql_drop_db( $database_name = null, $link_identifier = null )
	{
		Php7Compat::deprecated_function( __FUNCTION__ );
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 's|r' ) ) return null;
		$link = Php7Compat::link( __FUNCTION__, $link_identifier, func_num_args() > 1 );
		if( !$link ) return $link;
		return Php7Compat::query( __FUNCTION__, $link, 'DROP DATABASE ' . Php7Compat::quote_identifier( $database_name ), MYSQLI_STORE_RESULT ) === true;
	}
}

if( !function_exists( 'mysql_affected_rows' ) )
{
	function mysql_affected_rows( $link_identifier = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), '|r' ) ) return null;
		$link = Php7Compat::link( __FUNCTION__, $link_identifier, func_num_args() > 0 );
		if( !$link ) return $link;
		return mysqli_affected_rows( $link );
	}
}

if( !function_exists( 'mysql_insert_id' ) )
{
	function mysql_insert_id( $link_identifier = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), '|r' ) ) return null;
		$link = Php7Compat::link( __FUNCTION__, $link_identifier, func_num_args() > 0 );
		if( !$link ) return $link;
		return mysqli_insert_id( $link );
	}
}

if( !function_exists( 'mysql_info' ) )
{
	function mysql_info( $link_identifier = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), '|r' ) ) return null;
		$link = Php7Compat::link( __FUNCTION__, $link_identifier, func_num_args() > 0 );
		if( !$link ) return $link;
		$info = mysqli_info( $link );
		return $info === null ? false : $info;
	}
}

// ----------------------------------------------------------------------
// Errors
// ----------------------------------------------------------------------

if( !function_exists( 'mysql_errno' ) )
{
	function mysql_errno( $link_identifier = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), '|r' ) ) return null;
		if( func_num_args() > 0 )
		{
			$link = Php7Compat::given_link( __FUNCTION__, $link_identifier );
			if( !$link ) return $link;
		}
		else
		{
			// With no link, report the last connection failure, if any
			$link = Php7Compat::default_link();
			if( !$link ) return Php7Compat::$connect_errno !== 0 ? Php7Compat::$connect_errno : false;
		}
		return mysqli_errno( $link );
	}
}

if( !function_exists( 'mysql_error' ) )
{
	function mysql_error( $link_identifier = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), '|r' ) ) return null;
		if( func_num_args() > 0 )
		{
			$link = Php7Compat::given_link( __FUNCTION__, $link_identifier );
			if( !$link ) return $link;
		}
		else
		{
			// With no link, report the last connection failure, if any
			$link = Php7Compat::default_link();
			if( !$link ) return Php7Compat::$connect_error !== null ? Php7Compat::$connect_error : false;
		}
		return mysqli_error( $link );
	}
}

// ----------------------------------------------------------------------
// Escaping
// ----------------------------------------------------------------------

if( !function_exists( 'mysql_real_escape_string' ) )
{
	function mysql_real_escape_string( $unescaped_string = null, $link_identifier = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 's|r' ) ) return null;
		$link = Php7Compat::link( __FUNCTION__, $link_identifier, func_num_args() > 1 );
		if( !$link ) return $link;
		return mysqli_real_escape_string( $link, (string) $unescaped_string );
	}
}

if( !function_exists( 'mysql_escape_string' ) )
{
	function mysql_escape_string( $unescaped_string = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 's' ) ) return null;
		// This doesn't need a connection and doesn't take the connection's
		// character set into account.
		$escaped = strtr( (string) $unescaped_string, array(
			"\0" => '\\0',
			"\n" => '\\n',
			"\r" => '\\r',
			'\\' => '\\\\',
			"'" => "\\'",
			'"' => '\\"',
			"\x1a" => '\\Z',
		) );
		Php7Compat::deprecated( Php7Compat::name( __FUNCTION__ ) . '(): This function is deprecated; use mysql_real_escape_string() instead.' );
		return $escaped;
	}
}

// ----------------------------------------------------------------------
// Fetching rows
// ----------------------------------------------------------------------

if( !function_exists( 'mysql_fetch_array' ) )
{
	function mysql_fetch_array( $result = null, $result_type = null )
	{
		return Php7Compat::fetch_hash( __FUNCTION__, func_get_args(), MYSQL_BOTH );
	}
}

if( !function_exists( 'mysql_fetch_assoc' ) )
{
	function mysql_fetch_assoc( $result = null )
	{
		return Php7Compat::fetch_hash( __FUNCTION__, func_get_args(), MYSQL_ASSOC );
	}
}

if( !function_exists( 'mysql_fetch_row' ) )
{
	function mysql_fetch_row( $result = null )
	{
		return Php7Compat::fetch_hash( __FUNCTION__, func_get_args(), MYSQL_NUM );
	}
}

if( !function_exists( 'mysql_fetch_object' ) )
{
	function mysql_fetch_object( $result = null, $class_name = null, $params = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 'z|sz' ) ) return null;
		$argc = func_num_args();
		$class_name = $argc > 1 ? (string) $class_name : 'stdClass';
		if( !Php7Compat::result( __FUNCTION__, $result ) ) return false;

		// The original threw these exceptions after fetching the row, so the
		// row is still consumed. A missing class is left to mysqli, which
		// throws an Error where the original raised a fatal error.
		if( $argc > 2 && class_exists( $class_name ) )
		{
			$class = new ReflectionClass( $class_name );
			$message = null;
			if( !$class->getConstructor() ) $message = 'Class ' . $class->getName() . ' does not have a constructor hence you cannot use ctor_params';
			elseif( $params !== null && !is_array( $params ) ) $message = 'Parameter ctor_params must be an array';
			if( $message !== null )
			{
				if( Php7Compat::fetch( __FUNCTION__, $result, 'mysqli_fetch_row' ) === false ) return false;
				throw new Exception( $message );
			}
		}
		return Php7Compat::fetch( __FUNCTION__, $result, function( $result ) use ( $class_name, $params )
		{
			if( is_array( $params ) ) return mysqli_fetch_object( $result, $class_name, $params );
			return mysqli_fetch_object( $result, $class_name );
		} );
	}
}

if( !function_exists( 'mysql_fetch_lengths' ) )
{
	function mysql_fetch_lengths( $result = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 'r' ) ) return null;
		if( !Php7Compat::result( __FUNCTION__, $result ) ) return false;
		if( Php7Compat::$saved_lengths !== null && isset( Php7Compat::$saved_lengths[$result] ) ) return Php7Compat::$saved_lengths[$result];
		return mysqli_fetch_lengths( $result );
	}
}

if( !function_exists( 'mysql_result' ) )
{
	function mysql_result( $result = null, $row = null, $field = 0 )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 'rl|z' ) ) return null;
		if( !Php7Compat::result( __FUNCTION__, $result ) ) return false;

		$row = (int) $row;
		if( $row < 0 || $row >= Php7Compat::buffered_rows( $result ) )
		{
			Php7Compat::warn( __FUNCTION__, 'Unable to jump to row ' . $row . ' on MySQL result index ' . Php7Compat::id( $result ) );
			return false;
		}
		// Like the original: seek, then find the field, and only fetch the
		// row once the field is known to be valid
		mysqli_data_seek( $result, $row );
		Php7Compat::set_cursor( $result, $row );

		if( is_string( $field ) )
		{
			// "name" or "table.name", matched case-insensitively
			$dot = strpos( $field, '.' );
			$table_name = $dot === false ? null : substr( $field, 0, $dot );
			$field_name = $dot === false ? $field : substr( $field, $dot + 1 );
			$offset = null;
			mysqli_field_seek( $result, 0 );
			for( $i = 0; ( $info = mysqli_fetch_field( $result ) ) !== false; $i++ )
			{
				if( ( $table_name === null || strcasecmp( $info->table, $table_name ) === 0 ) && strcasecmp( $info->name, $field_name ) === 0 )
				{
					$offset = $i;
					break;
				}
			}
			if( $offset === null )
			{
				Php7Compat::warn( __FUNCTION__, $field . ' not found in MySQL result index ' . Php7Compat::id( $result ) );
				return false;
			}
		}
		else
		{
			$offset = (int) $field;
			if( $offset < 0 || $offset >= mysqli_num_fields( $result ) )
			{
				Php7Compat::warn( __FUNCTION__, 'Bad column offset specified' );
				return false;
			}
		}

		$values = mysqli_fetch_row( $result );
		Php7Compat::set_cursor( $result, $row + 1 );
		if( !is_array( $values ) ) return false;
		return $values[$offset];
	}
}

if( !function_exists( 'mysql_data_seek' ) )
{
	function mysql_data_seek( $result = null, $row_number = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 'rl' ) ) return null;
		if( !Php7Compat::result( __FUNCTION__, $result ) ) return false;
		$row_number = (int) $row_number;
		if( $row_number < 0 || $row_number >= Php7Compat::buffered_rows( $result ) )
		{
			Php7Compat::warn( __FUNCTION__, 'Offset ' . $row_number . ' is invalid for MySQL result index ' . Php7Compat::id( $result ) . ' (or the query data is unbuffered)' );
			return false;
		}
		mysqli_data_seek( $result, $row_number );
		Php7Compat::set_cursor( $result, $row_number );
		return true;
	}
}

if( !function_exists( 'mysql_num_rows' ) )
{
	function mysql_num_rows( $result = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 'r' ) ) return null;
		if( !Php7Compat::result( __FUNCTION__, $result ) ) return false;
		// For an unbuffered result with rows still to read, the original
		// returned the number of rows fetched so far.
		if( Php7Compat::is_unbuffered( $result ) && Php7Compat::is_pending( $result ) ) return Php7Compat::get_cursor( $result );
		if( Php7Compat::is_unbuffered( $result ) && PHP_VERSION_ID < 80100 ) return Php7Compat::get_cursor( $result );
		return (int) mysqli_num_rows( $result );
	}
}

if( !function_exists( 'mysql_free_result' ) )
{
	function mysql_free_result( $result = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 'r' ) ) return null;
		if( !Php7Compat::result( __FUNCTION__, $result ) ) return false;
		Php7Compat::forget_result( $result );
		mysqli_free_result( $result );
		return true;
	}
}

// ----------------------------------------------------------------------
// Fields
// ----------------------------------------------------------------------

if( !function_exists( 'mysql_num_fields' ) )
{
	function mysql_num_fields( $result = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 'r' ) ) return null;
		if( !Php7Compat::result( __FUNCTION__, $result ) ) return false;
		return mysqli_num_fields( $result );
	}
}

if( !function_exists( 'mysql_fetch_field' ) )
{
	function mysql_fetch_field( $result = null, $field_offset = 0 )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 'r|l' ) ) return null;
		if( !Php7Compat::result( __FUNCTION__, $result ) ) return false;
		if( func_num_args() > 1 )
		{
			$field_offset = (int) $field_offset;
			// The original warned about a bad offset, but then carried on
			// and returned the next field anyway.
			if( $field_offset < 0 || $field_offset >= mysqli_num_fields( $result ) ) Php7Compat::warn( __FUNCTION__, 'Bad field offset' );
			else mysqli_field_seek( $result, $field_offset );
		}
		$index = mysqli_field_tell( $result );
		$field = mysqli_fetch_field( $result );
		if( !$field ) return false;
		return Php7Compat::legacy_field( $result, $field, $index );
	}
}

if( !function_exists( 'mysql_field_seek' ) )
{
	function mysql_field_seek( $result = null, $field_offset = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 'rl' ) ) return null;
		if( !Php7Compat::result( __FUNCTION__, $result ) ) return false;
		$field_offset = (int) $field_offset;
		if( $field_offset < 0 || $field_offset >= mysqli_num_fields( $result ) )
		{
			Php7Compat::warn( __FUNCTION__, 'Field ' . $field_offset . ' is invalid for MySQL result index ' . Php7Compat::id( $result ) );
			return false;
		}
		mysqli_field_seek( $result, $field_offset );
		return true;
	}
}

if( !function_exists( 'mysql_field_name' ) )
{
	function mysql_field_name( $result = null, $field_offset = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 'rl' ) ) return null;
		if( !Php7Compat::result( __FUNCTION__, $result ) ) return false;
		$field = Php7Compat::field_info( __FUNCTION__, $result, $field_offset );
		return $field ? $field->name : false;
	}
}

if( !function_exists( 'mysql_field_table' ) )
{
	function mysql_field_table( $result = null, $field_offset = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 'rl' ) ) return null;
		if( !Php7Compat::result( __FUNCTION__, $result ) ) return false;
		$field = Php7Compat::field_info( __FUNCTION__, $result, $field_offset );
		return $field ? $field->table : false;
	}
}

if( !function_exists( 'mysql_field_len' ) )
{
	function mysql_field_len( $result = null, $field_offset = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 'rl' ) ) return null;
		if( !Php7Compat::result( __FUNCTION__, $result ) ) return false;
		$field = Php7Compat::field_info( __FUNCTION__, $result, $field_offset );
		return $field ? (int) $field->length : false;
	}
}

if( !function_exists( 'mysql_field_type' ) )
{
	function mysql_field_type( $result = null, $field_offset = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 'rl' ) ) return null;
		if( !Php7Compat::result( __FUNCTION__, $result ) ) return false;
		$field = Php7Compat::field_info( __FUNCTION__, $result, $field_offset );
		return $field ? Php7Compat::type_name( $field->type ) : false;
	}
}

if( !function_exists( 'mysql_field_flags' ) )
{
	function mysql_field_flags( $result = null, $field_offset = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 'rl' ) ) return null;
		if( !Php7Compat::result( __FUNCTION__, $result ) ) return false;
		$field = Php7Compat::field_info( __FUNCTION__, $result, $field_offset );
		return $field ? Php7Compat::flag_names( $field->flags ) : false;
	}
}

// ----------------------------------------------------------------------
// Listing databases, tables, fields and processes
// ----------------------------------------------------------------------

if( !function_exists( 'mysql_list_dbs' ) )
{
	function mysql_list_dbs( $link_identifier = null )
	{
		Php7Compat::deprecated_function( __FUNCTION__ );
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), '|r' ) ) return null;
		$given = func_num_args() > 0;
		$notice = Php7Compat::name( __FUNCTION__ ) . '(): This function is deprecated; use mysql_query() with SHOW DATABASES instead';
		if( $given ) Php7Compat::deprecated( $notice );
		$link = Php7Compat::link( __FUNCTION__, $link_identifier, $given );
		if( !$link ) return $link;
		if( !$given ) Php7Compat::deprecated( $notice );
		$result = Php7Compat::query( __FUNCTION__, $link, 'SHOW DATABASES', MYSQLI_STORE_RESULT );
		if( !$result ) Php7Compat::warn( __FUNCTION__, 'Unable to save MySQL query result' );
		return $result;
	}
}

if( !function_exists( 'mysql_list_tables' ) )
{
	function mysql_list_tables( $database = null, $link_identifier = null )
	{
		Php7Compat::deprecated_function( __FUNCTION__ );
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 's|r' ) ) return null;
		$link = Php7Compat::link( __FUNCTION__, $link_identifier, func_num_args() > 1 );
		if( !$link ) return $link;
		// Like the original, this selects the database as a side effect
		if( !Php7Compat::select_db( __FUNCTION__, $link, $database ) ) return false;
		$result = Php7Compat::query( __FUNCTION__, $link, 'SHOW TABLES', MYSQLI_STORE_RESULT );
		if( !$result ) Php7Compat::warn( __FUNCTION__, 'Unable to save MySQL query result' );
		return $result;
	}
}

if( !function_exists( 'mysql_list_fields' ) )
{
	function mysql_list_fields( $database_name = null, $table_name = null, $link_identifier = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 'ss|r' ) ) return null;
		$link = Php7Compat::link( __FUNCTION__, $link_identifier, func_num_args() > 2 );
		if( !$link ) return $link;
		// Like the original, this selects the database as a side effect, and
		// returns an empty result whose fields describe the table's columns.
		if( !Php7Compat::select_db( __FUNCTION__, $link, $database_name ) ) return false;
		$defaults = Php7Compat::column_defaults( __FUNCTION__, $link, $table_name );
		$result = Php7Compat::query( __FUNCTION__, $link, 'SELECT * FROM ' . Php7Compat::quote_identifier( $table_name ) . ' LIMIT 0', MYSQLI_STORE_RESULT );
		if( !$result )
		{
			Php7Compat::warn( __FUNCTION__, 'Unable to save MySQL query result' );
			return $result;
		}
		if( $defaults !== null && count( $defaults ) === mysqli_num_fields( $result ) )
		{
			if( Php7Compat::$list_defaults === null ) Php7Compat::$list_defaults = Php7Compat::object_map();
			Php7Compat::$list_defaults[$result] = $defaults;
		}
		return $result;
	}
}

if( !function_exists( 'mysql_list_processes' ) )
{
	function mysql_list_processes( $link_identifier = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), '|r' ) ) return null;
		$link = Php7Compat::link( __FUNCTION__, $link_identifier, func_num_args() > 0 );
		if( !$link ) return $link;
		$result = Php7Compat::query( __FUNCTION__, $link, 'SHOW PROCESSLIST', MYSQLI_STORE_RESULT );
		if( !$result ) Php7Compat::warn( __FUNCTION__, 'Unable to save MySQL query result' );
		return $result;
	}
}

// ----------------------------------------------------------------------
// Connection and server information
// ----------------------------------------------------------------------

if( !function_exists( 'mysql_set_charset' ) )
{
	function mysql_set_charset( $charset = null, $link_identifier = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 's|r' ) ) return null;
		$link = Php7Compat::link( __FUNCTION__, $link_identifier, func_num_args() > 1 );
		if( !$link ) return $link;
		Php7Compat::flush_pending( __FUNCTION__, $link );
		return (bool) Php7Compat::quiet( __FUNCTION__, function() use ( $link, $charset )
		{
			return mysqli_set_charset( $link, (string) $charset );
		} );
	}
}

if( !function_exists( 'mysql_client_encoding' ) )
{
	function mysql_client_encoding( $link_identifier = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), '|r' ) ) return null;
		$link = Php7Compat::link( __FUNCTION__, $link_identifier, func_num_args() > 0 );
		if( !$link ) return $link;
		return mysqli_character_set_name( $link );
	}
}

if( !function_exists( 'mysql_get_client_info' ) )
{
	function mysql_get_client_info()
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), '' ) ) return null;
		return mysqli_get_client_info();
	}
}

if( !function_exists( 'mysql_get_host_info' ) )
{
	function mysql_get_host_info( $link_identifier = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), '|r' ) ) return null;
		$link = Php7Compat::link( __FUNCTION__, $link_identifier, func_num_args() > 0 );
		if( !$link ) return $link;
		return mysqli_get_host_info( $link );
	}
}

if( !function_exists( 'mysql_get_proto_info' ) )
{
	function mysql_get_proto_info( $link_identifier = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), '|r' ) ) return null;
		$link = Php7Compat::link( __FUNCTION__, $link_identifier, func_num_args() > 0 );
		if( !$link ) return $link;
		return mysqli_get_proto_info( $link );
	}
}

if( !function_exists( 'mysql_get_server_info' ) )
{
	function mysql_get_server_info( $link_identifier = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), '|r' ) ) return null;
		$link = Php7Compat::link( __FUNCTION__, $link_identifier, func_num_args() > 0 );
		if( !$link ) return $link;
		return mysqli_get_server_info( $link );
	}
}

if( !function_exists( 'mysql_stat' ) )
{
	function mysql_stat( $link_identifier = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), '|r' ) ) return null;
		$link = Php7Compat::link( __FUNCTION__, $link_identifier, func_num_args() > 0 );
		if( !$link ) return $link;
		Php7Compat::flush_pending( __FUNCTION__, $link );
		return Php7Compat::quiet( __FUNCTION__, function() use ( $link )
		{
			return mysqli_stat( $link );
		} );
	}
}

if( !function_exists( 'mysql_thread_id' ) )
{
	function mysql_thread_id( $link_identifier = null )
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), '|r' ) ) return null;
		$link = Php7Compat::link( __FUNCTION__, $link_identifier, func_num_args() > 0 );
		if( !$link ) return $link;
		return mysqli_thread_id( $link );
	}
}

// ----------------------------------------------------------------------
// Deprecated aliases from the original extension
// ----------------------------------------------------------------------

// The last three aliases were not deprecated in PHP 5; the rest were.
if( !function_exists( 'mysql' ) ) { function mysql() { return Php7Compat::alias( __FUNCTION__, 'mysql_db_query', func_get_args(), true ); } }
if( !function_exists( 'mysql_fieldname' ) ) { function mysql_fieldname() { return Php7Compat::alias( __FUNCTION__, 'mysql_field_name', func_get_args(), true ); } }
if( !function_exists( 'mysql_fieldtable' ) ) { function mysql_fieldtable() { return Php7Compat::alias( __FUNCTION__, 'mysql_field_table', func_get_args(), true ); } }
if( !function_exists( 'mysql_fieldlen' ) ) { function mysql_fieldlen() { return Php7Compat::alias( __FUNCTION__, 'mysql_field_len', func_get_args(), true ); } }
if( !function_exists( 'mysql_fieldtype' ) ) { function mysql_fieldtype() { return Php7Compat::alias( __FUNCTION__, 'mysql_field_type', func_get_args(), true ); } }
if( !function_exists( 'mysql_fieldflags' ) ) { function mysql_fieldflags() { return Php7Compat::alias( __FUNCTION__, 'mysql_field_flags', func_get_args(), true ); } }
if( !function_exists( 'mysql_selectdb' ) ) { function mysql_selectdb() { return Php7Compat::alias( __FUNCTION__, 'mysql_select_db', func_get_args(), true ); } }
if( !function_exists( 'mysql_createdb' ) ) { function mysql_createdb() { return Php7Compat::alias( __FUNCTION__, 'mysql_create_db', func_get_args(), true ); } }
if( !function_exists( 'mysql_dropdb' ) ) { function mysql_dropdb() { return Php7Compat::alias( __FUNCTION__, 'mysql_drop_db', func_get_args(), true ); } }
if( !function_exists( 'mysql_freeresult' ) ) { function mysql_freeresult() { return Php7Compat::alias( __FUNCTION__, 'mysql_free_result', func_get_args(), true ); } }
if( !function_exists( 'mysql_numfields' ) ) { function mysql_numfields() { return Php7Compat::alias( __FUNCTION__, 'mysql_num_fields', func_get_args(), true ); } }
if( !function_exists( 'mysql_numrows' ) ) { function mysql_numrows() { return Php7Compat::alias( __FUNCTION__, 'mysql_num_rows', func_get_args(), true ); } }
if( !function_exists( 'mysql_listdbs' ) ) { function mysql_listdbs() { return Php7Compat::alias( __FUNCTION__, 'mysql_list_dbs', func_get_args(), true ); } }
if( !function_exists( 'mysql_listtables' ) ) { function mysql_listtables() { return Php7Compat::alias( __FUNCTION__, 'mysql_list_tables', func_get_args(), true ); } }
if( !function_exists( 'mysql_listfields' ) ) { function mysql_listfields() { return Php7Compat::alias( __FUNCTION__, 'mysql_list_fields', func_get_args(), true ); } }
if( !function_exists( 'mysql_dbname' ) ) { function mysql_dbname() { return Php7Compat::alias( __FUNCTION__, 'mysql_result', func_get_args(), true ); } }
if( !function_exists( 'mysql_db_name' ) ) { function mysql_db_name() { return Php7Compat::alias( __FUNCTION__, 'mysql_result', func_get_args(), false ); } }
if( !function_exists( 'mysql_tablename' ) ) { function mysql_tablename() { return Php7Compat::alias( __FUNCTION__, 'mysql_result', func_get_args(), false ); } }
if( !function_exists( 'mysql_table_name' ) ) { function mysql_table_name() { return Php7Compat::alias( __FUNCTION__, 'mysql_result', func_get_args(), false ); } }

/**
 * Check if a MySQL resource was successfully returned
 *
 * mysql_* functions returned resources, but these wrappers return mysqli
 * objects, so is_resource() checks in legacy code will fail. Replace them
 * with calls to this function, which works both with the wrappers and with
 * the original mysql extension.
 *
 * @param   mixed    $resource  the value to check
 * @return  boolean  true if resource, mysqli link or mysqli result, false otherwise
 */
if( !function_exists( 'is_mysql_resource' ) )
{
	function is_mysql_resource( $resource )
	{
		return is_resource( $resource ) || $resource instanceof mysqli || $resource instanceof mysqli_result;
	}
}

// ----------------------------------------------------------------------
// POSIX regular expressions
// ----------------------------------------------------------------------

if( !function_exists( 'ereg' ) )
{
	function ereg( $pattern = null, $string = null, &$regs = null )
	{
		Php7Compat::deprecated_function( __FUNCTION__ );
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 'Zs|Z' ) ) return null;
		return Php7Compat::ereg( __FUNCTION__, $pattern, $string, $regs, func_num_args() > 2, false );
	}
}

if( !function_exists( 'eregi' ) )
{
	function eregi( $pattern = null, $string = null, &$regs = null )
	{
		Php7Compat::deprecated_function( __FUNCTION__ );
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 'Zs|Z' ) ) return null;
		return Php7Compat::ereg( __FUNCTION__, $pattern, $string, $regs, func_num_args() > 2, true );
	}
}

if( !function_exists( 'ereg_replace' ) )
{
	function ereg_replace( $pattern = null, $replacement = null, $string = null )
	{
		Php7Compat::deprecated_function( __FUNCTION__ );
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 'ZZs' ) ) return null;
		return Php7Compat::ereg_replace( __FUNCTION__, $pattern, $replacement, $string, false );
	}
}

if( !function_exists( 'eregi_replace' ) )
{
	function eregi_replace( $pattern = null, $replacement = null, $string = null )
	{
		Php7Compat::deprecated_function( __FUNCTION__ );
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 'ZZs' ) ) return null;
		return Php7Compat::ereg_replace( __FUNCTION__, $pattern, $replacement, $string, true );
	}
}

if( !function_exists( 'split' ) )
{
	function split( $pattern = null, $string = null, $limit = -1 )
	{
		Php7Compat::deprecated_function( __FUNCTION__ );
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 'ss|l' ) ) return null;
		return Php7Compat::split( __FUNCTION__, $pattern, $string, $limit, false );
	}
}

if( !function_exists( 'spliti' ) )
{
	function spliti( $pattern = null, $string = null, $limit = -1 )
	{
		Php7Compat::deprecated_function( __FUNCTION__ );
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 'ss|l' ) ) return null;
		return Php7Compat::split( __FUNCTION__, $pattern, $string, $limit, true );
	}
}

if( !function_exists( 'sql_regcase' ) )
{
	function sql_regcase( $string = null )
	{
		Php7Compat::deprecated_function( __FUNCTION__ );
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 's' ) ) return null;
		$string = (string) $string;
		$out = '';
		$length = strlen( $string );
		for( $i = 0; $i < $length; $i++ )
		{
			$char = $string[$i];
			if( ( $char >= 'a' && $char <= 'z' ) || ( $char >= 'A' && $char <= 'Z' ) ) $out .= '[' . strtoupper( $char ) . strtolower( $char ) . ']';
			else $out .= $char;
		}
		return $out;
	}
}

// ----------------------------------------------------------------------
// Magic quotes (always off)
// ----------------------------------------------------------------------

if( !function_exists( 'get_magic_quotes_gpc' ) )
{
	function get_magic_quotes_gpc()
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), '' ) ) return null;
		return false;
	}
}

if( !function_exists( 'get_magic_quotes_runtime' ) )
{
	function get_magic_quotes_runtime()
	{
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), '' ) ) return null;
		return false;
	}
}

if( !function_exists( 'set_magic_quotes_runtime' ) )
{
	function set_magic_quotes_runtime( $new_setting = null )
	{
		Php7Compat::deprecated_function( __FUNCTION__ );
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 'b' ) ) return null;
		return Php7Compat::set_magic_quotes_runtime( __FUNCTION__, $new_setting );
	}
}

if( !function_exists( 'magic_quotes_runtime' ) )
{
	function magic_quotes_runtime( $new_setting = null )
	{
		Php7Compat::deprecated_function( __FUNCTION__ );
		if( !Php7Compat::parse( __FUNCTION__, func_get_args(), 'b' ) ) return null;
		return Php7Compat::set_magic_quotes_runtime( __FUNCTION__, $new_setting );
	}
}

///:~
