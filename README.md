# php7-compat
Wrapper functions around the deprecated `mysql_*` and `ereg*` functions for use in PHP 7

## Usage
In `php.ini`, add the following line (adjust the path depending on where you put the compatibility library):

    auto_prepend_file = "/usr/local/share/php7-compat/php7-compat.php"

Alternatively, use

    include( '/usr/local/share/php7-compat/php7-compat.php' );

as required.

## Notes
For use with large legacy codebases, where PHP needs to updated but rewriting the code is impractical. Includes wrappers for the most commonly used `mysql_*` functions, mapping them onto their closest `mysqli_*` counterpart. Also includes wrappers for the `ereg*` and `split*` functions, mapping them onto their `preg_*` equivalents.

Apache-licensed so you are free to use the code as you see fit, and we don't have to worry about software patents.

Use the code by cloning the repository, and then either via the `auto_prepend_file` option in php.ini (recommended), or loading it in individual files as required. The library can safely be loaded multiple times and in PHP 5 environments: it only generates the function definitions if they aren't already present.

## Limitations:
1. Doesn't always work reliably with codebases that have connections open to multiple databases within the same file. This is a fundamental limitation, as most of the `mysql_*` functions didn't require a database link as an argument, whereas many of the newer `mysqli_*` functions do. We store the most recently used database connection in a global variable, and default to using that if the link variable was not passed into the `mysql_*` function call.
2. Not all functions have been redefined. Contributions welcome! Missing functions are:
* `mysql_client_encoding`
* `mysql_create_db`
* `mysql_db_name`
* `mysql_fetch_lengths`
* `mysql_field_flags`
* `mysql_field_len`
* `mysql_field_seek`
* `mysql_field_table`
* `mysql_get_client_info`
* `mysql_get_host_info`
* `mysql_get_proto_info`
* `mysql_info`
* `mysql_list_processes`
* `mysql_list_tables`
* `mysql_ping`
* `mysql_set_charset`
* `mysql_stat`
* `mysql_tablename`
* `mysql_thread_id`
* `mysql_unbuffered_query`
