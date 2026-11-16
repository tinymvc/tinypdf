<?php

declare(strict_types=1);

use Spark\Pdf\Text\NativeHelper;
use Spark\Pdf\TextOptions;

foreach (['crash', 'timeout', 'oversize', 'truncated', 'protocol', 'id', 'capability', 'json'] as $failure) {
    test("Native helper $failure fails within a bounded time", function () use ($failure) {
        $path = dirname(__DIR__) . '/native/build/tinypdf-test-helper' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        check(is_file($path), 'Build the tinypdf-test-helper target before running failure checks.');
        $old = getenv('TINYPDF_TEST_FAILURE');
        putenv('TINYPDF_TEST_FAILURE=' . $failure);
        $start = microtime(true);
        try {
            throws(RuntimeException::class, fn() => (new NativeHelper(new TextOptions(helperPath: $path, helperTimeout: .2)))->capabilities());
            check(microtime(true) - $start < 2, 'Helper failures must not hang the PHP process');
        } finally {
            putenv($old === false ? 'TINYPDF_TEST_FAILURE' : 'TINYPDF_TEST_FAILURE=' . $old);
        }
    });
}
