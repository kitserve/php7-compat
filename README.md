# php7-compat

Drop-in replacements for the `mysql_*` and `ereg*` functions that were removed in PHP 7, for running legacy code on PHP 7 and PHP 8.

The wrappers aim to behave **as closely as possible to the original PHP 5 functions**, including return values, warnings and odd edge cases, so that legacy code keeps working without changes. The `mysql_*` functions are implemented on top of `mysqli`. The `ereg*` functions translate POSIX regular expressions into PCRE. Where exact emulation isn't possible, the differences are listed under [Known differences](#known-differences-from-the-original-functions).

[![Tests](https://github.com/kitserve/php7-compat/actions/workflows/tests.yml/badge.svg)](https://github.com/kitserve/php7-compat/actions/workflows/tests.yml)

## Requirements

* PHP 7.0 or later. The test suite runs on PHP 7.4 and 8.0 to 8.5, against MySQL 8.0 and MariaDB 10.11. PHP 7.0 to 7.3 aren't covered by the tests, because PHPUnit 9 doesn't support them.
* The `mysqli` extension.

## Usage

Clone the repository. Then, in `php.ini`, add the following line (adjust the path depending on where you put the library):

    auto_prepend_file = "/usr/local/share/php7-compat/php7-compat.php"

This is the recommended approach, as it guarantees the functions are available in every script. Alternatively, include the file where it's needed:

    include( '/usr/local/share/php7-compat/php7-compat.php' );

A `composer.json` is included, so the library can also be installed with Composer by adding this GitHub repository as a [VCS repository](https://getcomposer.org/doc/05-repositories.md#vcs). It isn't published on Packagist. Composer then loads it automatically via `vendor/autoload.php`.

The library is safe to load more than once. Each function is only defined if it doesn't already exist.

### Deprecation notices

Deprecation notices are raised where PHP 5 raised them: on connecting, and from deprecated functions such as `ereg()` and `mysql_db_query()`. Because they are `E_USER_DEPRECATED`, the common production setting `error_reporting = E_ALL & ~E_DEPRECATED` doesn't hide them. To switch them off, either add `& ~E_USER_DEPRECATED` to `error_reporting`, or define this constant before the first `mysql_*` / `ereg*` call:

    define( 'PHP7COMPAT_NO_DEPRECATIONS', true );

Only the library's own notices are affected. When using `auto_prepend_file`, you can prepend a small file that defines the constant and then includes this library.

## What's included

### `mysql_*` functions

All functions from the original `mysql` extension:

`mysql_affected_rows`, `mysql_client_encoding`, `mysql_close`, `mysql_connect`, `mysql_create_db`, `mysql_data_seek`, `mysql_db_name`, `mysql_db_query`, `mysql_drop_db`, `mysql_errno`, `mysql_error`, `mysql_escape_string`, `mysql_fetch_array`, `mysql_fetch_assoc`, `mysql_fetch_field`, `mysql_fetch_lengths`, `mysql_fetch_object`, `mysql_fetch_row`, `mysql_field_flags`, `mysql_field_len`, `mysql_field_name`, `mysql_field_seek`, `mysql_field_table`, `mysql_field_type`, `mysql_free_result`, `mysql_get_client_info`, `mysql_get_host_info`, `mysql_get_proto_info`, `mysql_get_server_info`, `mysql_info`, `mysql_insert_id`, `mysql_list_dbs`, `mysql_list_fields`, `mysql_list_processes`, `mysql_list_tables`, `mysql_num_fields`, `mysql_num_rows`, `mysql_pconnect`, `mysql_ping`, `mysql_query`, `mysql_real_escape_string`, `mysql_result`, `mysql_select_db`, `mysql_set_charset`, `mysql_stat`, `mysql_tablename`, `mysql_thread_id`, `mysql_unbuffered_query`

It also includes the old aliases `mysql` (for `mysql_db_query`), `mysql_dbname`, `mysql_fieldflags`, `mysql_fieldlen`, `mysql_fieldname`, `mysql_fieldtable`, `mysql_fieldtype`, `mysql_freeresult`, `mysql_listdbs`, `mysql_listfields`, `mysql_listtables`, `mysql_numfields`, `mysql_numrows`, `mysql_selectdb`, `mysql_createdb`, `mysql_dropdb` and `mysql_table_name`, plus the constants `MYSQL_ASSOC`, `MYSQL_NUM`, `MYSQL_BOTH` and `MYSQL_CLIENT_*`.

### POSIX regular expression functions

`ereg`, `eregi`, `ereg_replace`, `eregi_replace`, `split`, `spliti`, `sql_regcase`

### Magic quotes stubs

Magic quotes are always off: all four functions (`get_magic_quotes_gpc`, `get_magic_quotes_runtime`, `set_magic_quotes_runtime`, `magic_quotes_runtime`) return `false`.

### `is_mysql_resource()`

The original `mysql_*` functions returned resources; these wrappers return `mysqli` and `mysqli_result` objects. Legacy code that checks results with `is_resource()` will therefore see `false`. Replace those checks with `is_mysql_resource()`, which accepts both resources and the mysqli objects, so the code works with or without this library.

## mysqli exceptions

From PHP 8.1, mysqli throws exceptions by default, whereas the original functions returned `false`. Each wrapper switches mysqli's exceptions off for its own `mysqli` calls, then restores the application's `mysqli_report()` setting. Direct `mysqli_*` calls elsewhere in the application are unaffected.

## Known differences from the original functions

These are compromises where exact emulation is impossible or impractical:

1. **Warnings and notices** are `E_USER_WARNING` / `E_USER_NOTICE` rather than `E_WARNING` / `E_NOTICE`. They report the file and line inside this library, not the calling code. Messages that originally included a resource number (`MySQL result index 5`) show an internal object ID instead.
2. **Deprecation notices** are `E_USER_DEPRECATED` rather than `E_DEPRECATED`, so they need their own `error_reporting` setting to hide them (see [Deprecation notices](#deprecation-notices)).
3. **Links and results are objects, not resources**, so `is_resource()`, `get_resource_type()` and casting a link to an integer behave differently. See `is_mysql_resource()` above.
4. **Configuration** comes from the `mysqli.*` ini settings, not `mysql.*`. Settings with no mysqli equivalent, such as `mysql.connect_timeout`, `mysql.trace_mode`, `mysql.max_links` and `sql.safe_mode`, are ignored. `mysql_pconnect()` uses mysqli persistent connections, which are governed by the `mysqli.allow_persistent` and `mysqli.max_persistent` settings.
5. **`max_length` on PHP 8.1+:** mysqli no longer reports `max_length` in field metadata, so `mysql_fetch_field()` calculates it by scanning the result set once, then puts the row cursor back. This relies on the row cursor only being moved by the `mysql_*` wrappers. Mixing direct `mysqli_*` calls on the same result may leave the cursor in the wrong place. On PHP 7.x and 8.0, mysqli's own value is used.
6. **Unbuffered results** can't be seeked at all: `mysql_data_seek()` and `mysql_result()` always warn and fail on them.
7. **`mysql_create_db()` and `mysql_drop_db()`** only existed in PHP 5 builds linked against MySQL client libraries older than 4.0. They are provided here anyway, using `CREATE DATABASE` / `DROP DATABASE`.
8. **POSIX matching semantics:** PCRE finds the *first* matching alternative, while POSIX finds the *longest* match. For example, `ereg('a|ab', 'ab', $r)` sets `$r[0]` to `a` rather than `ab`. This only matters when alternatives or repetitions could match different lengths at the same position.
9. **`set_magic_quotes_runtime(true)`** gives a warning and returns `false`. PHP 5.4+ raised a fatal error with the same message, which would halt the script.
10. **Floats passed as strings** are converted using current PHP rules, which can give more digits than PHP 5 did (e.g. `0.30000000000000004` rather than `0.3`).

## Testing

See [TESTING.md](TESTING.md).

## Licence

Apache-licensed, so you are free to use the code as you see fit, and we don't have to worry about software patents. Contributions welcome!
