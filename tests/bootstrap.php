<?php

require __DIR__ . '/../vendor/autoload.php';

// Most tests assert on exact warnings, so deprecation notices are switched
// off except in the tests that check them (see DeprecationTest).
\Php7Compat::$deprecations = false;
